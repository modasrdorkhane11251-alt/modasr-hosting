<?php

declare(strict_types=1);

$botRoot = dirname(__DIR__, 2) . '/bot';
require $botRoot . '/api/hosting/bootstrap.php';

$passed = 0;
$failed = 0;
function section(string $name): void { echo "\n== {$name} ==\n"; }
function ok(bool $condition, string $label): void {
    global $passed, $failed;
    if ($condition) { ++$passed; echo "PASS: {$label}\n"; }
    else { ++$failed; echo "FAIL: {$label}\n"; }
}

$dsn = getenv('TEST_DB_DSN') ?: 'mysql:host=127.0.0.1;port=3307;dbname=mysql';
$user = getenv('TEST_DB_USER') ?: 'root';
$password = getenv('TEST_DB_PASSWORD') ?: '';
$rootPdo = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$controlDb = 'TESTONLY_hosting_control_' . bin2hex(random_bytes(6));
$rootPdo->exec("CREATE DATABASE `$controlDb` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$controlDsn = preg_replace('/;dbname=[^;]+/', ';dbname=' . $controlDb, $dsn, 1);
$central = new PDO($controlDsn, $user, $password, $rootPdo->getAttribute(PDO::ATTR_ERRMODE) ? [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
] : []);

$tenantKeys = [];
$dbUsers = [];
$secretBox = new HostingSecretBox(base64_encode(str_repeat('T', 32)));
$provisioner = new HostingDatabaseProvisioner($central, $rootPdo, $dsn, $user, $password, $secretBox, $botRoot);
$tenantA = $tenantB = null;
try {
    section('schema');
    hostingApplyCoreMigrations($central, $botRoot);
    hostingApplyCoreMigrations($central, $botRoot);
    $tables = $central->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    ok((int) $tables === 11, 'core migration is idempotent');

    section('tenant provisioning');
    $tenantA = $provisioner->createTenant('TESTONLY_owner_A');
    $tenantB = $provisioner->createTenant('TESTONLY_owner_B');
    $tenantKeys[] = $tenantA['tenant_key'];
    $tenantKeys[] = $tenantB['tenant_key'];
    $dbUsers[] = $tenantA['db_user'];
    $dbUsers[] = $tenantB['db_user'];
    ok($tenantA['db_name'] !== $tenantB['db_name'], 'tenant databases are distinct');
    ok($tenantA['db_user'] !== $tenantB['db_user'], 'tenant DB users are distinct');
    foreach ([$tenantA, $tenantB] as $tenant) {
        $count = $rootPdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $rootPdo->quote($tenant['db_name']))->fetchColumn();
        ok((int) $count >= 36, 'core schema installed for ' . $tenant['tenant_key']);
        foreach (['user', 'invoice', 'product'] as $required) {
            $exists = $rootPdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = ' . $rootPdo->quote($tenant['db_name']) . ' AND table_name = ' . $rootPdo->quote($required))->fetchColumn();
            ok((int) $exists === 1, 'required table ' . $required . ' exists');
        }
    }

    section('tenant isolation');
    $pdoA = $provisioner->openTenantPdo($tenantA['tenant_key']);
    $pdoB = $provisioner->openTenantPdo($tenantB['tenant_key']);
    ok($pdoA->query('SELECT DATABASE()')->fetchColumn() === $tenantA['db_name'], 'tenant A resolver opens tenant A database');
    ok($pdoB->query('SELECT DATABASE()')->fetchColumn() === $tenantB['db_name'], 'tenant B resolver opens tenant B database');
    $crossDenied = false;
    try {
        $pdoA->query('SELECT COUNT(*) FROM `' . $tenantB['db_name'] . '`.user');
    } catch (PDOException) {
        $crossDenied = true;
    }
    ok($crossDenied, 'tenant A cannot read tenant B');
    $credential = $central->prepare('SELECT ciphertext FROM hosting_credential WHERE tenant_key = ? AND purpose = "database"');
    $credential->execute([$tenantA['tenant_key']]);
    $cipher = (string) $credential->fetchColumn();
    ok(!str_contains($cipher, $tenantA['db_name']) && strlen($cipher) > 80, 'database credential is encrypted');

    section('instances and upgrades');
    $telegram = $provisioner->createInstance($tenantA['tenant_key'], 'modasr_vpn', 'telegram', 'TESTONLY_bot_A', 'TestBotA', 'TESTONLY_TELEGRAM_TOKEN');
    $soroush = $provisioner->createInstance($tenantA['tenant_key'], 'modasr_vpn', 'soroush', 'TESTONLY_bot_S', 'TestBotS', 'TESTONLY_SOROUSH_TOKEN');
    ok($telegram['tenant_key'] === $soroush['tenant_key'], 'new platform attaches to same tenant');
    ok($telegram['runtime_status'] === 'stopped' && $soroush['runtime_status'] === 'stopped', 'instances start stopped');
    $dbCount = $central->query('SELECT COUNT(*) FROM hosting_tenant')->fetchColumn();
    ok((int) $dbCount === 2, 'adding platform does not create another tenant database');
    $duplicate = false;
    try {
        $provisioner->createInstance($tenantA['tenant_key'], 'modasr_vpn', 'telegram', 'TESTONLY_bot_A', 'TestBotA', 'TESTONLY_TELEGRAM_TOKEN');
    } catch (RuntimeException $e) {
        $duplicate = $e->getMessage() === 'instance_already_exists';
    }
    ok($duplicate, 'duplicate instance rejected');

    section('RBAC and platform identities');
    $rbac = new HostingRbac($central);
    $rbac->bootstrapOwnerAliases();
    $ownerPrincipal = $rbac->principal('telegram', '900000001');
    $customerPrincipal = $rbac->principal('telegram', 'TESTONLY_customer');
    $sameNumericOtherPlatform = $rbac->principal('bale', 'TESTONLY_customer');
    ok((int) $ownerPrincipal['id'] !== (int) $customerPrincipal['id'], 'owner and customer principals are distinct');
    ok((int) $customerPrincipal['id'] !== (int) $sameNumericOtherPlatform['id'], 'same numeric ID on different platforms is not auto-merged');
    $rbac->assignRole((int) $customerPrincipal['id'], 'TENANT_OWNER', $tenantA['tenant_key']);
    ok($rbac->hasPermission((int) $ownerPrincipal['id'], '*', $tenantA['tenant_key']), 'platform owner has global access');
    ok($rbac->hasPermission((int) $customerPrincipal['id'], 'instances.restart', $tenantA['tenant_key']), 'tenant owner can restart own instance');
    ok(!$rbac->hasPermission((int) $customerPrincipal['id'], 'instances.restart', $tenantB['tenant_key']), 'tenant owner cannot access another tenant');
    ok(!$rbac->hasPermission((int) $customerPrincipal['id'], '*', $tenantA['tenant_key']), 'tenant role cannot become platform owner');

    section('instance transitions and audit');
    $audit = new HostingAuditLogger($central);
    $instances = new HostingInstanceRepository($central);
    $instances->transition($telegram['id'], $tenantA['tenant_key'], 'active', (int) $ownerPrincipal['id'], $audit);
    $instances->transition($telegram['id'], $tenantA['tenant_key'], 'stopped', (int) $ownerPrincipal['id'], $audit);
    $invalid = false;
    try {
        $instances->transition($telegram['id'], $tenantA['tenant_key'], 'provisioning', (int) $ownerPrincipal['id'], $audit);
    } catch (RuntimeException $e) {
        $invalid = $e->getMessage() === 'instance_transition_invalid';
    }
    ok($invalid, 'invalid instance transition rejected');
    $first = $audit->append((int) $ownerPrincipal['id'], 'PLATFORM_OWNER', $tenantA['tenant_key'], 'test.first', 'tenant', $tenantA['tenant_key'], 'TESTONLY_reason', ['token' => 'CANARY_SECRET', 'safe' => 'ok']);
    $second = $audit->append((int) $ownerPrincipal['id'], 'PLATFORM_OWNER', $tenantA['tenant_key'], 'test.second', 'tenant', $tenantA['tenant_key'], 'TESTONLY_reason', ['safe' => 'ok']);
    $auditRows = $central->prepare('SELECT previous_hash, entry_hash, metadata_json FROM hosting_audit_log WHERE request_id IN (?, ?) ORDER BY id');
    $auditRows->execute([$first['request_id'], $second['request_id']]);
    $rows = $auditRows->fetchAll();
    ok(count($rows) === 2 && $rows[1]['previous_hash'] === $first['entry_hash'], 'audit hash chain linked');
    ok(!str_contains($rows[0]['metadata_json'], 'CANARY_SECRET'), 'audit metadata redacts secrets');

    section('support sessions');
    $support = new HostingSupportService($central, $rbac, $audit);
    $session = $support->open((int) $ownerPrincipal['id'], $tenantA['tenant_key'], 'TESTONLY owner support', ['support.read', 'instances.restart'], 600);
    ok($session['status'] === 'active', 'owner support session starts active');
    $authorized = $support->authorize((int) $ownerPrincipal['id'], $session['id'], $tenantA['tenant_key'], 'instances.restart');
    ok($authorized['tenant_key'] === $tenantA['tenant_key'], 'support authorization is tenant scoped');
    $support->close((int) $ownerPrincipal['id'], $session['id'], $tenantA['tenant_key']);
    $closedDenied = false;
    try {
        $support->authorize((int) $ownerPrincipal['id'], $session['id'], $tenantA['tenant_key'], 'instances.restart');
    } catch (RuntimeException) {
        $closedDenied = true;
    }
    ok($closedDenied, 'closed support session cannot be reused');

    section('orders');
    $orderId = HostingAuditLogger::uuid();
    $stmt = $central->prepare('INSERT INTO hosting_order (id, order_number, tenant_key, plan_code, amount_toman, payment_status, fulfillment_status, source_platform, idempotency_key) VALUES (?, ?, ?, ?, ?, "paid", "not_started", "telegram", ?)');
    $stmt->execute([$orderId, 'TESTONLY_ORDER_1', $tenantA['tenant_key'], 'v:t', 300000, str_repeat('a', 64)]);
    $order = $central->query('SELECT source_platform, payment_status, fulfillment_status FROM hosting_order WHERE id = ' . $central->quote($orderId))->fetch();
    ok($order['source_platform'] === 'telegram' && $order['payment_status'] === 'paid' && $order['fulfillment_status'] === 'not_started', 'order stores source and separate payment state');
} finally {
    $pdoA = $pdoB = null;
    foreach ($dbUsers as $dbUser) {
        $rootPdo->exec("DROP USER IF EXISTS '$dbUser'@'%'");
    }
    foreach ($tenantKeys as $tenantKey) {
        $rootPdo->exec("DROP DATABASE IF EXISTS `modasr_tenant_$tenantKey`");
    }
    $central = null;
    $rootPdo->exec("DROP DATABASE IF EXISTS `$controlDb`");
}

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
