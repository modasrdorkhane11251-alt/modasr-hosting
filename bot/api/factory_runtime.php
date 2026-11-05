<?php

declare(strict_types=1);

function factoryWriteTenantStatus(int $factoryId, string $status): void
{
    if (!in_array($status, ['active', 'stopped'], true)) {
        throw new RuntimeException('invalid_runtime_action');
    }
    $root = '/var/www/modasr-hosting-tenants/' . $factoryId;
    $file = $root . '/tenant.json';
    $tenant = json_decode((string) file_get_contents($file), true);
    if (!is_array($tenant) || (int) ($tenant['id'] ?? 0) !== $factoryId) {
        throw new RuntimeException('tenant_status_failed');
    }
    $tenant['status'] = $status;
    $tmp = $root . '/tenant.json.tmp';
    file_put_contents($tmp, json_encode($tenant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
    chmod($tmp, 0640);
    if (!rename($tmp, $file)) {
        throw new RuntimeException('tenant_status_failed');
    }
}

function factoryRuntimeAction(PDO $pdo, int $factoryId, string $action, string $adminId): void
{
    $stmt = $pdo->prepare('SELECT * FROM factory_bot WHERE id = ?');
    $stmt->execute([$factoryId]);
    $bot = $stmt->fetch();
    if (!is_array($bot)) {
        throw new RuntimeException('bot_not_found');
    }
    if (!in_array($action, ['stop', 'start'], true)) {
        throw new RuntimeException('invalid_runtime_action');
    }
    $output = [];
    $code = 0;
    exec('sudo -n /usr/local/sbin/modasr-factory-spawn ' . $factoryId . ' ' . $action . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException('runtime_action_failed');
    }
    $status = $action === 'stop' ? 'stopped' : 'active';
    $update = $pdo->prepare('UPDATE factory_bot SET status = ? WHERE id = ?');
    $update->execute([$status, $factoryId]);
    $update = $pdo->prepare('UPDATE factory_order SET status = ? WHERE id = ?');
    $update->execute([$status === 'active' ? 'active' : 'stopped', $bot['order_id']]);
    factoryWriteTenantStatus($factoryId, $status);
    factoryTenantSyncRuntimeStatus($pdo, $factoryId, $status, $adminId);
    factoryLog($pdo, $adminId, 'runtime_' . $action, $bot['bot_username'], $status);
}
