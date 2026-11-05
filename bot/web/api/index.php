<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

function webActionError(Throwable $e): never
{
    $safe = [
        'web_auth_required', 'csrf_invalid', 'access_denied', 'tenant_not_found',
        'instance_not_found', 'product_not_found', 'panel_not_found', 'order_not_found',
        'runtime_action_failed', 'bot_not_found', 'invalid_runtime_action',
        'instance_transition_invalid', 'credential_not_found', 'web_magic_invalid',
    ];
    $message = $e->getMessage();
    webJson(['ok' => false, 'error' => in_array($message, $safe, true) ? $message : 'server_error'], 400);
    exit;
}

function webActionLimit(int $value): int
{
    return max(1, min(50, (int) $value));
}

function webInstanceRow(string $instanceId, array $session): array
{
    $stmt = webPdo()->prepare('SELECT * FROM hosting_bot_instance WHERE id = ? AND tenant_key = ?');
    $stmt->execute([$instanceId, $session['tenant_key']]);
    $row = $stmt->fetch();
    if (!is_array($row)) {
        throw new RuntimeException('instance_not_found');
    }
    return $row;
}

try {
    $session = webContext(webPdo());
    $action = (string) ($_GET['action'] ?? 'dashboard');
    $actorId = webActorId($session);
    $tenantPdo = webTenantPdo($session);
    $tenantKey = (string) $session['tenant_key'];

    switch ($action) {
        case 'dashboard':
            $metrics = [
                'users' => (int) $tenantPdo->query('SELECT COUNT(*) FROM user')->fetchColumn(),
                'balance' => (int) $tenantPdo->query('SELECT COALESCE(SUM(CAST(Balance AS SIGNED)), 0) FROM user')->fetchColumn(),
                'orders' => (int) $tenantPdo->query('SELECT COUNT(*) FROM invoice')->fetchColumn(),
                'products' => (int) $tenantPdo->query('SELECT COUNT(*) FROM product')->fetchColumn(),
                'instances' => 0,
            ];
            $instanceCount = webPdo()->prepare('SELECT COUNT(*) FROM hosting_bot_instance WHERE tenant_key = ?');
            $instanceCount->execute([$tenantKey]);
            $metrics['instances'] = (int) $instanceCount->fetchColumn();
            $instanceStmt = webPdo()->prepare('SELECT runtime_status, COUNT(*) AS total FROM hosting_bot_instance WHERE tenant_key = ? GROUP BY runtime_status');
            $instanceStmt->execute([$tenantKey]);
            $metrics['instance_status'] = $instanceStmt->fetchAll();
            $metrics['active_instances'] = 0;
            foreach ($metrics['instance_status'] as $status) {
                if ($status['runtime_status'] === 'active') {
                    $metrics['active_instances'] = (int) $status['total'];
                }
            }
            $orders = $tenantPdo->query('SELECT id_invoice, id_user, username, name_product, price_product, time_sell, Status FROM invoice ORDER BY time_sell DESC LIMIT 8')->fetchAll();
            $auditStmt = webPdo()->prepare('SELECT action, object_type, object_id, actor_role, created_at FROM hosting_audit_log WHERE tenant_key = ? ORDER BY id DESC LIMIT 8');
            $auditStmt->execute([$tenantKey]);
            webJson(['ok' => true, 'metrics' => $metrics, 'orders' => $orders, 'audit' => $auditStmt->fetchAll(), 'actor' => $actorId]);
            break;

        case 'users':
            $limit = webActionLimit($_GET['limit'] ?? 25);
            $offset = max(0, (int) ($_GET['offset'] ?? 0));
            $q = trim((string) ($_GET['q'] ?? ''));
            $params = [];
            $where = '';
            if ($q !== '') {
                $where = 'WHERE id LIKE :id OR username LIKE :username';
                $params = [':id' => '%' . $q . '%', ':username' => '%' . $q . '%'];
            }
            $stmt = $tenantPdo->prepare("SELECT id, username, Balance, agent, `register`, last_message_time, User_Status FROM user $where ORDER BY `register` DESC LIMIT $limit OFFSET $offset");
            $stmt->execute($params);
            webJson(['ok' => true, 'items' => $stmt->fetchAll(), 'limit' => $limit, 'offset' => $offset]);
            break;

        case 'products':
            $stmt = $tenantPdo->query('SELECT code_product, name_product, price_product, Location, Service_time, category, status_product, display_order FROM product ORDER BY display_order, id LIMIT 100');
            webJson(['ok' => true, 'items' => $stmt->fetchAll()]);
            break;

        case 'products.toggle':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('method_invalid');
            }
            webVerifyCsrf($session);
            webAuthorize($session, 'tenant.manage');
            $code = (string) ($_POST['code_product'] ?? '');
            $stmt = $tenantPdo->prepare('SELECT code_product, status_product FROM product WHERE code_product = ?');
            $stmt->execute([$code]);
            $product = $stmt->fetch();
            if (!is_array($product)) {
                throw new RuntimeException('product_not_found');
            }
            $next = $product['status_product'] === 'active' ? 'disable' : 'active';
            $update = $tenantPdo->prepare('UPDATE product SET status_product = ? WHERE code_product = ?');
            $update->execute([$next, $code]);
            webAudit($session, 'product.status', 'product', $code, null, ['from' => $product['status_product'], 'to' => $next]);
            webJson(['ok' => true, 'status_product' => $next]);
            break;

        case 'orders':
            $status = (string) ($_GET['status'] ?? '');
            $limit = webActionLimit($_GET['limit'] ?? 30);
            $params = [];
            $where = '';
            if ($status !== '') {
                if (!in_array($status, ['active', 'Unpaid', 'end_of_time', 'end_of_volume', 'sendedwarn', 'send_on_hold', 'paid'], true)) {
                    throw new RuntimeException('status_invalid');
                }
                $where = 'WHERE Status = :status';
                $params[':status'] = $status;
            }
            $stmt = $tenantPdo->prepare("SELECT id_invoice, id_user, username, Service_location, time_sell, name_product, price_product, Volume, Service_time, Status, payable_amount FROM invoice $where ORDER BY time_sell DESC LIMIT $limit");
            $stmt->execute($params);
            webJson(['ok' => true, 'items' => $stmt->fetchAll()]);
            break;

        case 'payments':
            $limit = webActionLimit($_GET['limit'] ?? 30);
            $stmt = $tenantPdo->prepare('SELECT id, id_user, id_order, time, price, Payment_Method, payment_Status, id_invoice FROM Payment_report ORDER BY id DESC LIMIT ' . $limit);
            $stmt->execute();
            webJson(['ok' => true, 'items' => $stmt->fetchAll()]);
            break;

        case 'panels':
            $stmt = $tenantPdo->query('SELECT id, code_panel, name_panel, status, type, agent, display_order FROM marzban_panel ORDER BY display_order, id LIMIT 100');
            webJson(['ok' => true, 'items' => $stmt->fetchAll()]);
            break;

        case 'panels.toggle':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('method_invalid');
            }
            webVerifyCsrf($session);
            webAuthorize($session, 'tenant.manage');
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $tenantPdo->prepare('SELECT id, status FROM marzban_panel WHERE id = ?');
            $stmt->execute([$id]);
            $panel = $stmt->fetch();
            if (!is_array($panel)) {
                throw new RuntimeException('panel_not_found');
            }
            $next = $panel['status'] === 'active' ? 'disable' : 'active';
            $update = $tenantPdo->prepare('UPDATE marzban_panel SET status = ? WHERE id = ?');
            $update->execute([$next, $id]);
            webAudit($session, 'panel.status', 'panel', (string) $id, null, ['from' => $panel['status'], 'to' => $next]);
            webJson(['ok' => true, 'status' => $next]);
            break;

        case 'instances':
            $stmt = webPdo()->prepare('SELECT id, product_code, platform, username_snapshot, runtime_kind, runtime_status, service_name, adapter_version, product_version, factory_bot_id, last_health_at, last_error_code FROM hosting_bot_instance WHERE tenant_key = ? ORDER BY platform');
            $stmt->execute([$tenantKey]);
            webJson(['ok' => true, 'items' => $stmt->fetchAll()]);
            break;

        case 'instances.action':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('method_invalid');
            }
            webVerifyCsrf($session);
            $instanceId = (string) ($_POST['instance_id'] ?? '');
            $runtimeAction = (string) ($_POST['runtime_action'] ?? '');
            if (!in_array($runtimeAction, ['start', 'stop', 'restart'], true)) {
                throw new RuntimeException('invalid_runtime_action');
            }
            $permission = 'instances.' . ($runtimeAction === 'start' ? 'start' : ($runtimeAction === 'stop' ? 'stop' : 'restart'));
            webAuthorize($session, $permission);
            $instance = webInstanceRow($instanceId, $session);
            $factoryId = (int) ($instance['factory_bot_id'] ?? 0);
            if ($factoryId <= 0) {
                throw new RuntimeException('instance_not_found');
            }
            if ($runtimeAction === 'restart') {
                factoryRuntimeAction(webPdo(), $factoryId, 'stop', $actorId);
                factoryRuntimeAction(webPdo(), $factoryId, 'start', $actorId);
            } else {
                factoryRuntimeAction(webPdo(), $factoryId, $runtimeAction, $actorId);
            }
            webAudit($session, 'instance.' . $runtimeAction, 'bot_instance', $instanceId, null, ['factory_id' => $factoryId]);
            webJson(['ok' => true, 'runtime_status' => $runtimeAction === 'stop' ? 'stopped' : 'active']);
            break;

        case 'export.orders':
            $rows = $tenantPdo->query('SELECT id_invoice, id_user, username, name_product, price_product, time_sell, Status FROM invoice ORDER BY time_sell DESC LIMIT 1000')->fetchAll(PDO::FETCH_NUM);
            webCsv('modasr-orders.csv', ['order', 'user_id', 'username', 'product', 'amount_toman', 'time', 'status'], $rows);
            break;

        case 'export.payments':
            $rows = $tenantPdo->query('SELECT id, id_user, id_order, price, Payment_Method, payment_Status, time FROM Payment_report ORDER BY id DESC LIMIT 1000')->fetchAll(PDO::FETCH_NUM);
            webCsv('modasr-payments.csv', ['id', 'user_id', 'order_id', 'amount_toman', 'method', 'status', 'time'], $rows);
            break;

        case 'settings':
            $row = $tenantPdo->query('SELECT Bot_Status, limit_usertest_all, id_support, affiliatespercentage FROM setting LIMIT 1')->fetch();
            webJson(['ok' => true, 'settings' => $row ?: []]);
            break;

        case 'settings.save':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('method_invalid');
            }
            webVerifyCsrf($session);
            webAuthorize($session, 'tenant.manage');
            $allowed = [
                'Bot_Status' => ['botstatuson', 'botstatusoff'],
                'limit_usertest_all' => 'int:0:1000',
                'id_support' => 'string:64',
                'affiliatespercentage' => 'int:0:100',
            ];
            $input = (array) ($_POST['settings'] ?? []);
            $tenantPdo->beginTransaction();
            try {
                $current = $tenantPdo->query('SELECT Bot_Status, limit_usertest_all, id_support, affiliatespercentage FROM setting LIMIT 1 FOR UPDATE')->fetch();
                $next = [];
                foreach ($allowed as $key => $rule) {
                    if (!array_key_exists($key, $input)) {
                        $next[$key] = $current[$key] ?? null;
                        continue;
                    }
                    $value = $input[$key];
                    if (str_starts_with($rule, 'int:')) {
                        [, $min, $max] = explode(':', $rule);
                        $value = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => (int) $min, 'max_range' => (int) $max]]);
                        if ($value === false) {
                            throw new RuntimeException('setting_invalid');
                        }
                    } else {
                        $value = trim((string) $value);
                        if (mb_strlen($value) > 64 || !preg_match('/^@?[A-Za-z0-9_]+$/', $value)) {
                            throw new RuntimeException('setting_invalid');
                        }
                    }
                    $next[$key] = $value;
                }
                $stmt = $tenantPdo->prepare('UPDATE setting SET Bot_Status = ?, limit_usertest_all = ?, id_support = ?, affiliatespercentage = ?');
                $stmt->execute([
                    $next['Bot_Status'], $next['limit_usertest_all'], $next['id_support'], $next['affiliatespercentage'],
                ]);
                webAudit($session, 'settings.save', 'setting', $tenantKey, null, ['keys' => array_keys($next)]);
                $tenantPdo->commit();
                webJson(['ok' => true, 'settings' => $next]);
            } catch (Throwable $e) {
                if ($tenantPdo->inTransaction()) {
                    $tenantPdo->rollBack();
                }
                throw $e;
            }
            break;

        case 'support':
            $rows = $tenantPdo->query('SELECT id, Tracking, idsupport, iduser, name_departman, text, result, time, status FROM support_message ORDER BY id DESC LIMIT 30')->fetchAll();
            webJson(['ok' => true, 'items' => $rows]);
            break;

        case 'support.reply':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('method_invalid');
            }
            webVerifyCsrf($session);
            webAuthorize($session, 'support.read');
            $userId = trim((string) ($_POST['user_id'] ?? ''));
            $instanceId = (string) ($_POST['instance_id'] ?? '');
            $text = trim(strip_tags((string) ($_POST['text'] ?? '')));
            if ($userId === '' || $text === '' || mb_strlen($text) > 2000) {
                throw new RuntimeException('support_invalid');
            }
            $instance = webInstanceRow($instanceId, $session);
            $token = webInstanceCredential((string) $instance['id'], $session);
            factoryPlatformRequest($instance['platform'], $token, 'sendMessage', [
                'chat_id' => $userId,
                'text' => "💬 پاسخ پشتیبانی:\n\n" . $text,
            ]);
            $tracking = bin2hex(random_bytes(8));
            $stmt = $tenantPdo->prepare('INSERT INTO support_message (Tracking, idsupport, iduser, name_departman, text, result, time, status) VALUES (?, ?, ?, ?, ?, ?, ?, "Pending")');
            $stmt->execute([$tracking, $actorId, $userId, 'پنل وب', $text, '0', date('Y-m-d H:i:s')]);
            webAudit($session, 'support.reply', 'support_message', $tracking, null, ['instance_id' => $instanceId]);
            webJson(['ok' => true, 'tracking' => $tracking]);
            break;

        case 'backup.list':
            if ($session['role'] !== 'PLATFORM_OWNER') {
                webJson(['ok' => true, 'items' => [], 'owner_only' => true]);
                break;
            }
            $files = glob('/root/modasr-backups/bot-factory-*.tar.enc') ?: [];
            rsort($files, SORT_STRING);
            $items = [];
            foreach (array_slice($files, 0, 20) as $file) {
                $items[] = [
                    'id' => basename($file),
                    'size' => filesize($file),
                    'created_at' => date('Y-m-d H:i:s', filemtime($file)),
                    'sha256' => hash_file('sha256', $file),
                ];
            }
            webJson(['ok' => true, 'items' => $items]);
            break;

        case 'backup.create':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('method_invalid');
            }
            webVerifyCsrf($session);
            if ($session['role'] !== 'PLATFORM_OWNER') {
                throw new RuntimeException('access_denied');
            }
            $output = [];
            $code = 0;
            exec('sudo -n /usr/local/sbin/modasr-factory-backup 2>&1', $output, $code);
            if ($code !== 0) {
                throw new RuntimeException('backup_failed');
            }
            $files = glob('/root/modasr-backups/bot-factory-*.tar.enc') ?: [];
            rsort($files, SORT_STRING);
            $latest = $files[0] ?? null;
            if (!$latest || !is_file($latest)) {
                throw new RuntimeException('backup_failed');
            }
            $item = ['id' => basename($latest), 'size' => filesize($latest), 'created_at' => date('Y-m-d H:i:s', filemtime($latest)), 'sha256' => hash_file('sha256', $latest)];
            webAudit($session, 'backup.create', 'backup', $item['id'], null, ['sha256' => $item['sha256']]);
            webJson(['ok' => true, 'item' => $item]);
            break;

        case 'backup.download':
            if ($session['role'] !== 'PLATFORM_OWNER') {
                throw new RuntimeException('access_denied');
            }
            $id = (string) ($_GET['id'] ?? '');
            if (!preg_match('/^bot-factory-[a-z0-9-]+\.tar\.enc$/', $id) || !is_file('/root/modasr-backups/' . $id)) {
                throw new RuntimeException('order_not_found');
            }
            $path = '/root/modasr-backups/' . $id;
            header('Content-Type: application/octet-stream');
            header('Content-Length: ' . filesize($path));
            header('Content-Disposition: attachment; filename="' . $id . '"');
            readfile($path);
            exit;

        case 'audit':
            $stmt = webPdo()->prepare('SELECT action, object_type, object_id, actor_role, reason, metadata_json, previous_hash, entry_hash, created_at FROM hosting_audit_log WHERE tenant_key = ? ORDER BY id DESC LIMIT 50');
            $stmt->execute([$tenantKey]);
            webJson(['ok' => true, 'items' => $stmt->fetchAll()]);
            break;

        case 'auth.logout':
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                throw new RuntimeException('method_invalid');
            }
            webVerifyCsrf($session);
            webLogout(webPdo());
            webJson(['ok' => true]);
            break;

        default:
            webJson(['ok' => false, 'error' => 'route_not_found'], 404);
    }
} catch (Throwable $e) {
    webActionError($e);
}
