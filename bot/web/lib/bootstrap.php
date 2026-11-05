<?php

declare(strict_types=1);

require '/var/www/modasr-hosting-factory/config.php';
require_once '/var/www/modasr-hosting-full/bot/api/factory_tenant.php';
require_once '/var/www/modasr-hosting-full/bot/api/factory_catalog.php';
require_once '/var/www/modasr-hosting-full/bot/api/factory_runtime.php';
require_once __DIR__ . '/Auth.php';

function webPdo(): PDO
{
    global $pdo;
    return $pdo;
}

function webEsc(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function webJson(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function webTenantInfo(array $session): array
{
    $stmt = webPdo()->prepare('SELECT * FROM hosting_tenant WHERE tenant_key = ?');
    $stmt->execute([$session['tenant_key']]);
    $tenant = $stmt->fetch();
    if (!is_array($tenant)) {
        throw new RuntimeException('tenant_not_found');
    }
    return $tenant;
}

function webTenantPdo(array $session): PDO
{
    $tenant = webTenantInfo($session);
    $env = factoryTenantDatabaseEnvironment(webPdo(), (string) $tenant['tenant_key']);
    return new PDO(
        'mysql:host=127.0.0.1;port=3307;dbname=' . $env['DB_NAME'] . ';charset=utf8mb4',
        $env['DB_USER'],
        $env['DB_PASSWORD'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function webActorId(array $session): string
{
    $stmt = webPdo()->prepare('SELECT platform_user_id FROM hosting_principal WHERE id = ?');
    $stmt->execute([(int) $session['principal_id']]);
    return (string) $stmt->fetchColumn();
}

function webAuthorize(array $session, string $permission): void
{
    $rbac = new HostingRbac(webPdo());
    $rbac->requirePermission((int) $session['principal_id'], $permission, (string) $session['tenant_key']);
}

function webAudit(array $session, string $action, string $objectType, string $objectId, ?string $reason = null, array $metadata = []): void
{
    $logger = new HostingAuditLogger(webPdo());
    $logger->append(
        (int) $session['principal_id'],
        (string) $session['role'],
        (string) $session['tenant_key'],
        $action,
        $objectType,
        $objectId,
        $reason,
        $metadata
    );
}

function webInstanceCredential(string $instanceId, array $session): string
{
    $stmt = webPdo()->prepare('SELECT c.ciphertext FROM hosting_bot_instance i JOIN hosting_credential c ON c.id = i.credential_id WHERE i.id = ? AND i.tenant_key = ? AND c.purpose = "bot_token" AND c.revoked_at IS NULL');
    $stmt->execute([$instanceId, $session['tenant_key']]);
    $cipher = (string) $stmt->fetchColumn();
    if ($cipher === '') {
        throw new RuntimeException('credential_not_found');
    }
    return (new HostingSecretBox())->decrypt($cipher);
}

function webCsv(string $filename, array $headers, array $rows): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        $safe = [];
        foreach ($row as $value) {
            $value = (string) $value;
            if (preg_match('/^[=+\-@]/', $value)) {
                $value = "'" . $value;
            }
            $safe[] = $value;
        }
        fputcsv($out, $safe);
    }
    fclose($out);
    exit;
}
