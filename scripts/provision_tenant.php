<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli' || posix_getuid() !== 0) {
    fwrite(STDERR, "root_cli_required\n");
    exit(1);
}

$ownerId = (string) ($argv[1] ?? '');
if (!preg_match('/^[A-Za-z0-9_:\-]{1,64}$/', $ownerId)) {
    fwrite(STDERR, "owner_invalid\n");
    exit(1);
}

$botRoot = dirname(__DIR__) . '/bot';
require $botRoot . '/api/hosting/bootstrap.php';

$dsn = getenv('HOSTING_PROVISION_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=modasr_hosting_factory_staging';
$user = getenv('HOSTING_PROVISION_USER') ?: 'root';
$password = getenv('HOSTING_PROVISION_PASSWORD') ?: '';
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
$pdo = new PDO($dsn, $user, $password, $options);
$provisionPdo = new PDO($dsn, $user, $password, $options);

hostingApplyCoreMigrations($pdo, $botRoot);
$provisioner = new HostingDatabaseProvisioner(
    $pdo,
    $provisionPdo,
    $dsn,
    $user,
    $password,
    new HostingSecretBox(),
    $botRoot
);

$existing = $pdo->prepare('SELECT tenant_key, owner_id, db_name, db_user, schema_version, status FROM hosting_tenant WHERE owner_id = ? AND status = "active" ORDER BY created_at DESC LIMIT 1');
$existing->execute([$ownerId]);
$tenant = $existing->fetch();
if (!is_array($tenant)) {
    $tenant = $provisioner->createTenant($ownerId);
}

$rbac = new HostingRbac($pdo);
$rbac->bootstrapOwnerAliases();

echo json_encode([
    'tenant_key' => $tenant['tenant_key'],
    'owner_id' => $tenant['owner_id'],
    'db_name' => $tenant['db_name'],
    'db_user' => $tenant['db_user'],
    'schema_version' => (int) $tenant['schema_version'],
    'status' => $tenant['status'],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
