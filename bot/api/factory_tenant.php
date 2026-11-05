<?php

declare(strict_types=1);

require_once '/var/www/modasr-hosting-full/bot/api/hosting/bootstrap.php';
require_once '/var/www/modasr-hosting-full/bot/web/lib/Auth.php';

function factoryTenantCoreEnabled(): bool
{
    return (string) getenv('HOSTING_TENANT_CORE_V1') === '1';
}

function factoryTenantFindOrCreate(PDO $pdo, string $ownerId): array
{
    hostingApplyCoreMigrations($pdo, '/var/www/modasr-hosting-full/bot');
    $stmt = $pdo->prepare('SELECT * FROM hosting_tenant WHERE owner_id = ? AND status = "active" ORDER BY created_at DESC LIMIT 1');
    $stmt->execute([$ownerId]);
    $tenant = $stmt->fetch();
    if (is_array($tenant)) {
        return $tenant;
    }

    $output = [];
    $code = 0;
    exec('sudo -n /usr/local/sbin/modasr-tenant-db-provision ' . escapeshellarg($ownerId), $output, $code);
    if ($code !== 0) {
        throw new RuntimeException('tenant_provision_failed');
    }
    $json = trim(implode("\n", $output));
    $tenant = json_decode($json, true);
    if (!is_array($tenant) || !preg_match('/^[a-f0-9]{24}$/', (string) ($tenant['tenant_key'] ?? ''))) {
        throw new RuntimeException('tenant_provision_failed');
    }
    return $tenant;
}

function factoryTenantProvisioner(PDO $pdo): HostingDatabaseProvisioner
{
    return new HostingDatabaseProvisioner(
        $pdo,
        $pdo,
        'mysql:host=127.0.0.1;port=3307;dbname=mysql',
        '',
        '',
        new HostingSecretBox(),
        '/var/www/modasr-hosting-full/bot'
    );
}

function factoryTenantSyncInstances(PDO $pdo, string $ownerId, array $plan, array $identities, array $tokens, int $factoryId = 0): array
{
    $tenant = factoryTenantFindOrCreate($pdo, $ownerId);
    $rbac = new HostingRbac($pdo);
    $ownerPrincipal = $rbac->principal('telegram', $ownerId);
    $rbac->assignRole((int) $ownerPrincipal['id'], 'TENANT_OWNER', (string) $tenant['tenant_key']);
    $provisioner = factoryTenantProvisioner($pdo);
    $instances = [];
    foreach ($plan['platforms'] as $platform) {
        $externalId = (string) $identities[$platform]['id'];
        $externalHash = hash('sha256', $externalId);
        $stmt = $pdo->prepare('SELECT * FROM hosting_bot_instance WHERE tenant_key = ? AND product_code = "modasr_vpn" AND platform = ?');
        $stmt->execute([$tenant['tenant_key'], $platform]);
        $existing = $stmt->fetch();
        if (is_array($existing)) {
            if (!hash_equals((string) $existing['external_bot_id_hash'], $externalHash)) {
                throw new RuntimeException('instance_already_exists');
            }
            $instances[] = $existing;
            continue;
        }
        $newInstance = $provisioner->createInstance(
            (string) $tenant['tenant_key'],
            'modasr_vpn',
            $platform,
            $externalId,
            (string) $identities[$platform]['username'],
            (string) $tokens[$platform],
            '1.0',
            '1.0'
        );
        if ($factoryId > 0) {
            $link = $pdo->prepare('UPDATE hosting_bot_instance SET factory_bot_id = ? WHERE id = ?');
            $link->execute([$factoryId, $newInstance['id']]);
            $newInstance['factory_bot_id'] = $factoryId;
        }
        $instances[] = $newInstance;
    }
    return ['tenant' => $tenant, 'instances' => $instances];
}

function factoryTenantDatabaseEnvironment(PDO $pdo, string $tenantKey): array
{
    $stmt = $pdo->prepare('SELECT t.db_name, t.db_user, c.ciphertext FROM hosting_tenant t JOIN hosting_credential c ON c.tenant_key = t.tenant_key AND c.purpose = "database" AND c.revoked_at IS NULL WHERE t.tenant_key = ? AND t.status = "active"');
    $stmt->execute([$tenantKey]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('tenant_not_found');
    }
    return [
        'DB_NAME' => (string) $row['db_name'],
        'DB_USER' => (string) $row['db_user'],
        'DB_PASSWORD' => (new HostingSecretBox())->decrypt((string) $row['ciphertext']),
    ];
}

function factoryTenantRecordOrder(PDO $pdo, string $tenantKey, int $factoryOrderId, string $planCode, int $amountToman, string $sourcePlatform): void
{
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO hosting_order
        (id, order_number, tenant_key, plan_code, amount_toman, payment_status, fulfillment_status, source_platform, idempotency_key)
        VALUES (?, ?, ?, ?, ?, ?, "stopped", ?, ?)'
    );
    $stmt->execute([
        HostingAuditLogger::uuid(),
        'FACTORY-' . $factoryOrderId,
        $tenantKey,
        $planCode,
        $amountToman,
        $amountToman > 0 ? 'paid' : 'free',
        $sourcePlatform,
        hash('sha256', 'factory_order|' . $factoryOrderId),
    ]);
}

function factoryTenantSyncRuntimeStatus(PDO $pdo, int $factoryId, string $status, string $adminId): void
{
    if (!factoryTenantCoreEnabled() || !in_array($status, ['active', 'stopped'], true)) {
        return;
    }
    $tenantFile = '/var/www/modasr-hosting-tenants/' . $factoryId . '/tenant.json';
    $tenant = json_decode((string) @file_get_contents($tenantFile), true);
    $tenantKey = (string) ($tenant['tenant_key'] ?? '');
    if (!preg_match('/^[a-f0-9]{24}$/', $tenantKey)) {
        return;
    }
    $stmt = $pdo->prepare('UPDATE hosting_bot_instance SET runtime_status = ? WHERE tenant_key = ?');
    $stmt->execute([$status, $tenantKey]);
    $stmt = $pdo->prepare('UPDATE hosting_order SET fulfillment_status = ? WHERE tenant_key = ?');
    $stmt->execute([$status, $tenantKey]);
    $rbac = new HostingRbac($pdo);
    $principal = $rbac->principal('telegram', $adminId);
    $audit = new HostingAuditLogger($pdo);
    $audit->append((int) $principal['id'], 'PLATFORM_OWNER', $tenantKey, 'instance.' . $status, 'tenant', $tenantKey, 'factory_runtime_action', ['factory_id' => $factoryId]);
}

function factoryWebPanelUrl(PDO $pdo, string $ownerId, bool $platformOwner): string
{
    $tenant = factoryTenantFindOrCreate($pdo, $ownerId);
    $rbac = new HostingRbac($pdo);
    $principal = $rbac->principal('telegram', $ownerId);
    $role = $platformOwner ? 'PLATFORM_OWNER' : 'TENANT_OWNER';
    $token = webIssueMagicToken($pdo, (string) $tenant['tenant_key'], (int) $principal['id'], $role);
    $base = rtrim((string) (getenv('MODASR_WEB_BASE_URL') ?: 'https://bot.modasr.site/web/index.php'), '/');
    $logger = new HostingAuditLogger($pdo);
    $logger->append((int) $principal['id'], $role, (string) $tenant['tenant_key'], 'web.magic_link', 'web_session', $tenant['tenant_key'], null, ['role' => $role]);
    return $base . '?token=' . $token;
}
