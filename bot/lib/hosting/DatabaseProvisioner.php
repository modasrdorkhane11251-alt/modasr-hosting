<?php

declare(strict_types=1);

if (!function_exists('assertSqlIdentifier')) {
    function assertSqlIdentifier($name, $allowFieldExpr = false): void
    {
        if ($name === null) {
            return;
        }
        $pattern = $allowFieldExpr ? '/^[\p{L}\p{N}_*,()\s.`]+$/u' : '/^[\p{L}\p{N}_.`]+$/u';
        if (!preg_match($pattern, (string) $name)) {
            throw new InvalidArgumentException('Invalid SQL identifier');
        }
    }
}

require_once __DIR__ . '/../../db/Schema.php';
require_once __DIR__ . '/SecretBox.php';
require_once __DIR__ . '/AuditLogger.php';

final class HostingDatabaseProvisioner
{
    public function __construct(
        private PDO $centralPdo,
        private PDO $provisionPdo,
        private string $provisionDsn,
        private string $provisionUser,
        private string $provisionPassword,
        private HostingSecretBox $secretBox,
        private string $botRoot
    ) {
        $this->provisionPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function createTenant(string $ownerId): array
    {
        if ($ownerId === '' || strlen($ownerId) > 64) {
            throw new RuntimeException('owner_invalid');
        }
        $tenantKey = bin2hex(random_bytes(12));
        $dbName = 'modasr_tenant_' . $tenantKey;
        $dbUser = 'tenant_' . $tenantKey;
        $dbPassword = bin2hex(random_bytes(24));
        $createdDatabase = false;
        $createdUser = false;
        $stage = 'database';
        $this->centralPdo->beginTransaction();
        try {
            $this->provisionPdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $createdDatabase = true;
            $stage = 'user';
            $this->provisionPdo->exec("CREATE USER '$dbUser'@'%' IDENTIFIED BY '$dbPassword'");
            $createdUser = true;
            $stage = 'grants';
            $this->provisionPdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, LOCK TABLES ON `$dbName`.* TO '$dbUser'@'%'");

            $stage = 'schema';
            $tenantPdo = $this->newTenantPdo($dbName, $this->provisionUser, $this->provisionPassword);
            $schema = new Schema($tenantPdo, [
                'adminnumber' => $ownerId,
                'textbotlang' => [
                    'db_defaults' => [
                        'namecardNotSet' => 'تنظیم نشده',
                        'departmanGeneral' => '☎️ بخش عمومی',
                    ],
                ],
            ]);
            $schema->applyTables($this->botRoot . '/db/tables', require $this->botRoot . '/db/tables.php');
            $schema->applyIndexes(require $this->botRoot . '/db/indexes.php');
            foreach (['user', 'invoice', 'product'] as $required) {
                if (!$schema->tableExists($required)) {
                    throw new RuntimeException('tenant_schema_incomplete');
                }
            }

            $stage = 'control_write';
            $credentialId = HostingAuditLogger::uuid();
            $stmt = $this->centralPdo->prepare('INSERT INTO hosting_tenant (tenant_key, owner_id, db_name, db_user, schema_version, status) VALUES (?, ?, ?, ?, 1, "active")');
            $stmt->execute([$tenantKey, $ownerId, $dbName, $dbUser]);
            $stmt = $this->centralPdo->prepare('INSERT INTO hosting_credential (id, tenant_key, purpose, ciphertext, key_version) VALUES (?, ?, "database", ?, 1)');
            $stmt->execute([$credentialId, $tenantKey, $this->secretBox->encrypt($dbPassword)]);
            $this->centralPdo->commit();

            return [
                'tenant_key' => $tenantKey,
                'owner_id' => $ownerId,
                'db_name' => $dbName,
                'db_user' => $dbUser,
                'credential_id' => $credentialId,
                'schema_version' => 1,
                'status' => 'active',
            ];
        } catch (Throwable $e) {
            error_log('tenant_provision_failed stage=' . $stage . ' type=' . get_class($e) . ' code=' . (string) $e->getCode());
            if ($this->centralPdo->inTransaction()) {
                $this->centralPdo->rollBack();
            }
            if ($createdUser) {
                $this->provisionPdo->exec("DROP USER IF EXISTS '$dbUser'@'%'");
            }
            if ($createdDatabase) {
                $this->provisionPdo->exec("DROP DATABASE IF EXISTS `$dbName`");
            }
            throw new RuntimeException('tenant_provision_failed');
        }
    }

    public function openTenantPdo(string $tenantKey): PDO
    {
        $stmt = $this->centralPdo->prepare('SELECT t.db_name, t.db_user, c.ciphertext FROM hosting_tenant t JOIN hosting_credential c ON c.tenant_key = t.tenant_key AND c.purpose = "database" AND c.revoked_at IS NULL WHERE t.tenant_key = ? AND t.status = "active"');
        $stmt->execute([$tenantKey]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('tenant_not_found');
        }
        $password = $this->secretBox->decrypt((string) $row['ciphertext']);
        return $this->newTenantPdo((string) $row['db_name'], (string) $row['db_user'], $password);
    }

    public function createInstance(
        string $tenantKey,
        string $productCode,
        string $platform,
        string $externalBotId,
        string $username,
        string $botToken,
        string $adapterVersion = '1.0',
        string $productVersion = '1.0'
    ): array {
        if (!in_array($platform, ['telegram', 'bale', 'rubika', 'soroush'], true)) {
            throw new RuntimeException('platform_not_supported');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $productCode) || $externalBotId === '' || $username === '' || $botToken === '') {
            throw new RuntimeException('instance_invalid');
        }
        $tenant = $this->getTenant($tenantKey);
        if ((string) $tenant['status'] !== 'active') {
            throw new RuntimeException('tenant_not_ready');
        }

        $instanceId = HostingAuditLogger::uuid();
        $credentialId = HostingAuditLogger::uuid();
        $serviceName = 'modasr-tenant-' . $tenantKey . '-' . $platform;
        $externalHash = hash('sha256', $externalBotId);
        $runtimeKind = $platform === 'rubika' ? 'webhook' : 'long_polling';
        $this->centralPdo->beginTransaction();
        try {
            $stmt = $this->centralPdo->prepare('INSERT INTO hosting_credential (id, tenant_key, instance_id, purpose, ciphertext, key_version) VALUES (?, ?, ?, "bot_token", ?, 1)');
            $stmt->execute([$credentialId, $tenantKey, $instanceId, $this->secretBox->encrypt($botToken)]);
            $stmt = $this->centralPdo->prepare(
                'INSERT INTO hosting_bot_instance
                (id, tenant_key, product_code, platform, credential_id, external_bot_id_hash, username_snapshot, runtime_kind, runtime_status, service_name, adapter_version, product_version)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, "stopped", ?, ?, ?)'
            );
            $stmt->execute([
                $instanceId,
                $tenantKey,
                $productCode,
                $platform,
                $credentialId,
                $externalHash,
                $username,
                $runtimeKind,
                $serviceName,
                $adapterVersion,
                $productVersion,
            ]);
            $this->centralPdo->commit();
        } catch (Throwable $e) {
            if ($this->centralPdo->inTransaction()) {
                $this->centralPdo->rollBack();
            }
            if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
                throw new RuntimeException('instance_already_exists');
            }
            throw $e;
        }

        return [
            'id' => $instanceId,
            'tenant_key' => $tenantKey,
            'product_code' => $productCode,
            'platform' => $platform,
            'credential_id' => $credentialId,
            'runtime_status' => 'stopped',
            'service_name' => $serviceName,
        ];
    }

    public function getTenant(string $tenantKey): array
    {
        $stmt = $this->centralPdo->prepare('SELECT * FROM hosting_tenant WHERE tenant_key = ?');
        $stmt->execute([$tenantKey]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('tenant_not_found');
        }
        return $row;
    }

    private function newTenantPdo(string $dbName, string $user, string $password): PDO
    {
        $dsn = preg_replace('/;dbname=[^;]+/', ';dbname=' . $dbName, $this->provisionDsn, 1);
        if (!str_contains((string) $dsn, 'dbname=')) {
            $dsn = $this->provisionDsn . ';dbname=' . $dbName;
        }
        return new PDO((string) $dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
