<?php

declare(strict_types=1);

require '/var/www/modasr-hosting-factory/config.php';
require_once '/var/www/modasr-hosting-full/bot/api/factory_workflow.php';
require_once '/var/www/modasr-hosting-full/bot/api/factory_payment.php';
require_once '/var/www/modasr-hosting-full/bot/api/factory_catalog.php';

$providedSecret = (string) ($_GET['secret'] ?? '');
$relaySecret = (string) getenv('MODASR_RELAY_SECRET');
if ($relaySecret === '' || !hash_equals($relaySecret, $providedSecret)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['handled' => true, 'status' => false, 'msg' => 'unauthorized']);
    return;
}

function factoryEnsureSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_creation (
            owner_id VARCHAR(64) PRIMARY KEY,
            step VARCHAR(24) NOT NULL,
            bot_name VARCHAR(64) NULL,
            token_ciphertext TEXT NULL,
            plan_code VARCHAR(24) NOT NULL DEFAULT "vpn",
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            expires_at BIGINT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_order (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_id VARCHAR(64) NOT NULL,
            bot_name VARCHAR(64) NOT NULL,
            plan_code VARCHAR(24) NOT NULL,
            amount_toman BIGINT NOT NULL DEFAULT 0,
            status VARCHAR(24) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_factory_order_owner (owner_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_bot (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id INT UNSIGNED NOT NULL,
            owner_id VARCHAR(64) NOT NULL,
            bot_id VARCHAR(32) NOT NULL,
            bot_name VARCHAR(64) NOT NULL,
            bot_username VARCHAR(64) NOT NULL,
            token_ciphertext TEXT NOT NULL,
            service_name VARCHAR(96) NOT NULL,
            status VARCHAR(24) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_factory_bot_id (bot_id),
            UNIQUE KEY uniq_factory_bot_service (service_name),
            KEY idx_factory_bot_owner (owner_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_setting (
            setting_key VARCHAR(64) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_by VARCHAR(64) NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_event (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            owner_id VARCHAR(64) NOT NULL,
            action VARCHAR(48) NOT NULL,
            bot_username VARCHAR(64) NULL,
            detail VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_factory_event_owner (owner_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function factoryNormalizeToken(string $token): string
{
    $token = trim($token);
    $token = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $token) ?? $token;
    $token = str_replace('：', ':', $token);
    $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $arabic = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    return str_replace(array_merge($persian, $arabic), ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'], $token);
}

function factoryNormalizeBotName(string $name): string
{
    $name = strtolower(trim($name));
    $name = preg_replace('/[\s\-]+/u', '_', $name) ?? $name;
    $name = preg_replace('/[^a-z0-9_]/', '', $name) ?? $name;
    $name = preg_replace('/^[^a-z]+/', '', $name) ?? $name;
    if ($name === '') {
        return '';
    }
    if (!str_ends_with($name, '_bot')) {
        $name = str_ends_with($name, 'bot') ? substr($name, 0, -3) . '_bot' : $name . '_bot';
    }
    return strlen($name) > 31 ? substr($name, 0, 31) : $name;
}

function factoryApi(string $token, string $method, array $params = [], int $timeout = 30): array|bool
{
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $error = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($error !== 0 || $raw === false) {
        throw new RuntimeException('provider_unreachable');
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('provider_invalid_response');
    }
    if (($decoded['ok'] ?? false) !== true) {
        $description = strtolower((string) ($decoded['description'] ?? ''));
        if (in_array($status, [400, 401], true) && (str_contains($description, 'unauthorized') || str_contains($description, 'invalid token') || str_contains($description, 'bot token') || str_contains($description, 'token'))) {
            throw new RuntimeException('provider_unauthorized');
        }
        throw new RuntimeException('provider_rejected_' . $status);
    }
    return $decoded['result'] ?? [];
}

function factoryReply(int|string $chatId, string $text, array $keyboard = []): void
{
    $token = (string) getenv('TELEGRAM_TOKEN');
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard !== []) {
        $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }
    factoryApi($token, 'sendMessage', $payload);
}

function factoryMenu(): array
{
    global $isOwner;
    $keyboard = ['inline_keyboard' => [
        [
            ['text' => '🛒 خرید و ساخت ربات اختصاصی', 'callback_data' => '/create'],
        ],
        [
            ['text' => '📂 ربات‌های من', 'callback_data' => '/mybots'],
            ['text' => '🧾 سفارش‌های من', 'callback_data' => '/myorders'],
        ],
        [
            ['text' => '💎 پلن و قیمت', 'callback_data' => '/plans'],
            ['text' => '👤 حساب کاربری', 'callback_data' => '/account'],
        ],
        [
            ['text' => '🎫 آموزش', 'callback_data' => '/tutorial'],
            ['text' => '🎧 پشتیبانی', 'callback_data' => '/support'],
        ],
        [
            ['text' => '🔔 رویدادهای من', 'callback_data' => '/notifications'],
        ],
    ]];
    if ($isOwner) {
        $keyboard['inline_keyboard'][] = [['text' => '👑 مدیریت مالک', 'callback_data' => '/admin']];
    }
    return $keyboard;
}

function factoryPaymentPanel(PDO $pdo, array $order): array
{
    $orderId = (int) $order['id'];
    $amount = (int) $order['amount_toman'];
    $amountLabel = number_format($amount) . ' تومان';
    $statusLabel = factoryOrderStatusLabel((string) $order['status']);
    $card = factorySetting($pdo, 'payment_card_number', '');
    $paymentGuide = preg_match('/^\d{12,19}$/', $card)
        ? "\n\n💳 شماره کارت:\n<code>{$card}</code>\nپس از کارت‌به‌کارت، دکمه «ارسال رسید» را بزن."
        : "\n\nبرای دریافت اطلاعات پرداخت با @modasrpanel در تماس باش و سپس رسید را همین‌جا ارسال کن.";
    $keyboard = ['inline_keyboard' => [
        [['text' => '📤 ارسال رسید', 'callback_data' => 'factory_receipt_' . $orderId]],
        [['text' => '🔄 بررسی پرداخت و ادامه', 'callback_data' => 'factory_confirm']],
        [
            ['text' => '🧾 سفارش‌های من', 'callback_data' => '/myorders'],
            ['text' => '❌ لغو سفارش', 'callback_data' => 'factory_cancelorder_' . $orderId],
        ],
    ]];

    return [
        'text' => "💳 <b>پرداخت سفارش #{$orderId}</b>\n\nمبلغ: {$amountLabel}\nوضعیت: {$statusLabel}\n\nربات هنوز ساخته نشده و تا ثبت paid ساخته نمی‌شود.{$paymentGuide}",
        'keyboard' => $keyboard,
    ];
}

function factoryLog(PDO $pdo, string $ownerId, string $action, ?string $username = null, ?string $detail = null): void
{
    $stmt = $pdo->prepare('INSERT INTO factory_event (owner_id, action, bot_username, detail) VALUES (?, ?, ?, ?)');
    $stmt->execute([$ownerId, $action, $username, $detail]);
}

function factorySetting(PDO $pdo, string $key, string $default): string
{
    $stmt = $pdo->prepare('SELECT setting_value FROM factory_setting WHERE setting_key = ?');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function factorySetSetting(PDO $pdo, string $key, string $value, string $updatedBy): void
{
    $stmt = $pdo->prepare('INSERT INTO factory_setting (setting_key, setting_value, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([$key, $value, $updatedBy]);
}

function factoryPriceToman(PDO $pdo): int
{
    $price = (int) factorySetting($pdo, 'vpn_price_toman', '300000');
    if ($price < 0 || $price > 1000000000) {
        return 300000;
    }
    return $price;
}

function factoryRegisterCustomer(PDO $pdo, string $ownerId): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS factory_customer (
            owner_id VARCHAR(64) PRIMARY KEY,
            first_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $stmt = $pdo->prepare('INSERT INTO factory_customer (owner_id) VALUES (?) ON DUPLICATE KEY UPDATE last_seen_at = CURRENT_TIMESTAMP');
    $stmt->execute([$ownerId]);
}

function factoryPending(PDO $pdo, string $ownerId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM factory_creation WHERE owner_id = ? AND expires_at > ?');
    $stmt->execute([$ownerId, time()]);
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

function factoryClearPending(PDO $pdo, string $ownerId): void
{
    $stmt = $pdo->prepare('DELETE FROM factory_creation WHERE owner_id = ?');
    $stmt->execute([$ownerId]);
}

function factorySavePending(PDO $pdo, string $ownerId, string $step, ?string $botName = null, ?string $cipher = null, ?string $planCode = null): void
{
    $stmt = $pdo->prepare('INSERT INTO factory_creation (owner_id, step, bot_name, token_ciphertext, plan_code, attempts, expires_at) VALUES (?, ?, ?, ?, COALESCE(?, "vpn"), 0, ?) ON DUPLICATE KEY UPDATE step = VALUES(step), bot_name = COALESCE(VALUES(bot_name), bot_name), token_ciphertext = COALESCE(VALUES(token_ciphertext), token_ciphertext), plan_code = COALESCE(?, plan_code), expires_at = VALUES(expires_at), updated_at = CURRENT_TIMESTAMP');
    $stmt->execute([$ownerId, $step, $botName, $cipher, $planCode, time() + 900, $planCode]);
}

function factoryEncrypt(string $plain): string
{
    $key = base64_decode((string) getenv('MODASR_FACTORY_KEY'), true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('factory_key_invalid');
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($cipher === false) {
        throw new RuntimeException('factory_encryption_failed');
    }
    return base64_encode(json_encode(['iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($cipher)], JSON_THROW_ON_ERROR));
}

function factoryDecrypt(string $encoded): string
{
    $key = base64_decode((string) getenv('MODASR_FACTORY_KEY'), true);
    $package = json_decode(base64_decode($encoded, true), true);
    if ($key === false || strlen($key) !== 32 || !is_array($package)) {
        throw new RuntimeException('factory_key_invalid');
    }
    $iv = base64_decode((string) $package['iv'], true);
    $tag = base64_decode((string) $package['tag'], true);
    $cipher = base64_decode((string) $package['data'], true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        throw new RuntimeException('factory_decryption_failed');
    }
    return $plain;
}

function factoryAssertCapacity(PDO $pdo, string $ownerId): void
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM factory_bot WHERE owner_id = ? AND status IN ('provisioning', 'active', 'stopped')");
    $stmt->execute([$ownerId]);
    $customerLimit = max(1, min(5, (int) factorySetting($pdo, 'customer_bot_limit', '2')));
    if ((int) $stmt->fetchColumn() >= $customerLimit) {
        throw new RuntimeException('customer_bot_limit');
    }
    $stmt = $pdo->query("SELECT COUNT(*) FROM factory_bot WHERE status IN ('provisioning', 'active', 'stopped')");
    $globalLimit = max(5, min(20, (int) factorySetting($pdo, 'global_bot_limit', '10')));
    if ((int) $stmt->fetchColumn() >= $globalLimit) {
        throw new RuntimeException('staging_capacity_full');
    }
}

function factoryQuarantineMirzaBackup(int $factoryId, array $backup): string
{
    $fileId = (string) ($backup['file_id'] ?? '');
    $name = basename((string) ($backup['file_name'] ?? ''));
    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($fileId === '' || !in_array($extension, ['sql', 'zip'], true) || (int) ($backup['file_size'] ?? 0) > 50 * 1024 * 1024) {
        throw new RuntimeException('backup_invalid');
    }
    $file = factoryApi((string) getenv('TELEGRAM_TOKEN'), 'getFile', ['file_id' => $fileId]);
    $path = (string) ($file['file_path'] ?? '');
    if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/') || !preg_match('/^[A-Za-z0-9_\-.\/]+$/', $path)) {
        throw new RuntimeException('backup_invalid');
    }

    $root = '/var/www/modasr-hosting-tenants/' . $factoryId . '/import';
    if (!mkdir($root, 0770, true) && !is_dir($root)) {
        throw new RuntimeException('backup_quarantine_failed');
    }
    $target = $root . '/mirza-backup.' . $extension;
    $tmp = $target . '.tmp';
    $url = 'https://api.telegram.org/file/bot' . (string) getenv('TELEGRAM_TOKEN') . '/' . $path;
    $ch = curl_init($url);
    $fp = fopen($tmp, 'wb');
    if ($fp === false) {
        throw new RuntimeException('backup_quarantine_failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_MAXFILESIZE => 50 * 1024 * 1024,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fp);
    if ($ok === false || $status !== 200 || !is_file($tmp) || filesize($tmp) <= 0 || filesize($tmp) > 50 * 1024 * 1024) {
        @unlink($tmp);
        throw new RuntimeException('backup_quarantine_failed');
    }
    chmod($tmp, 0600);
    if (!rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException('backup_quarantine_failed');
    }
    return $target;
}

function factoryProvisionRuntime(PDO $pdo, string $ownerId, string $orderId, string $botName, array $bundle, string $planCode): array
{
    $plan = factoryPlan($planCode);
    $tokens = $bundle['tokens'] ?? [];
    $identities = $bundle['identities'] ?? [];
    if (!is_array($tokens) || !is_array($identities)) {
        throw new RuntimeException('credentials_invalid');
    }
    foreach ($plan['platforms'] as $platform) {
        if (empty($tokens[$platform]) || empty($identities[$platform]['id']) || empty($identities[$platform]['username'])) {
            throw new RuntimeException('credentials_incomplete');
        }
    }
    if (array_diff(array_keys($tokens), $plan['platforms']) !== []) {
        throw new RuntimeException('credentials_invalid');
    }

    $lockName = substr('factory_runtime_' . hash('sha256', $orderId . '|' . $ownerId), 0, 60);
    factoryAcquireLock($pdo, $lockName, 'runtime_lock_failed');
    try {
        $existing = $pdo->prepare('SELECT id, service_name, status FROM factory_bot WHERE order_id = ?');
        $existing->execute([(int) $orderId]);
        $existingBot = $existing->fetch();
        if (is_array($existingBot)) {
            if (in_array((string) $existingBot['status'], ['active', 'stopped'], true)) {
                return ['factory_id' => (int) $existingBot['id'], 'service' => (string) $existingBot['service_name'], 'status' => (string) $existingBot['status']];
            }
            throw new RuntimeException('runtime_recovery_required');
        }

        $order = factoryAssertOrderPayable($pdo, $orderId, $ownerId);
        if (factoryPlan((string) $order['plan_code'])['code'] !== $plan['code']) {
            throw new RuntimeException('order_plan_mismatch');
        }
        factoryAssertCapacity($pdo, $ownerId);

        $primary = $plan['platforms'][0];
        $botId = substr(hash('sha256', $primary . '|' . $identities[$primary]['id']), 0, 32);
        $username = (string) $identities[$primary]['username'];
        $stmt = $pdo->prepare('SELECT id FROM factory_bot WHERE bot_id = ?');
        $stmt->execute([$botId]);
        if ($stmt->fetch()) {
            throw new RuntimeException('bot_already_exists');
        }

        $credentialsJson = json_encode(['tokens' => $tokens, 'identities' => $identities], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $service = 'modasr-hosting-tenant-' . $orderId . '.service';
        $stmt = $pdo->prepare('INSERT INTO factory_bot (order_id, owner_id, bot_id, bot_name, bot_username, token_ciphertext, service_name, status) VALUES (?, ?, ?, ?, ?, ?, ?, "stopped")');
        $stmt->execute([$orderId, $ownerId, $botId, $botName, $username, factoryEncrypt($credentialsJson), $service]);
        $factoryId = (int) $pdo->lastInsertId();
        $service = 'modasr-hosting-tenant-' . $factoryId . '.service';
        $update = $pdo->prepare('UPDATE factory_bot SET service_name = ? WHERE id = ?');
        $update->execute([$service, $factoryId]);

        $root = '/var/www/modasr-hosting-tenants/' . $factoryId;
        if (!mkdir($root, 0770, true) && !is_dir($root)) {
            throw new RuntimeException('runtime_directory_failed');
        }
        $tenant = [
            'id' => $factoryId,
            'slug' => 'tenant-' . $factoryId,
            'platform' => $primary,
            'platforms' => $plan['platforms'],
            'mode' => 'long_polling_and_webhook',
            'status' => 'stopped',
            'fresh_install' => true,
            'owner_id' => $ownerId,
            'root_owner_ids' => array_filter([
                'telegram' => (string) getenv('MODASR_OWNER_TELEGRAM_ID'),
                'rubika' => (string) getenv('MODASR_OWNER_RUBIKA_ID'),
                'soroush' => (string) getenv('MODASR_OWNER_SOROUSH_ID'),
            ]),
            'bot_id' => $botId,
            'bot_name' => $botName,
            'bot_username' => $username,
            'plan_code' => $plan['code'],
            'webhook_secret' => bin2hex(random_bytes(24)),
            'backup' => !empty($bundle['mirza_backup']) ? ['status' => 'quarantined'] : null,
        ];
        file_put_contents($root . '/tenant.json', json_encode($tenant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($root . '/tenant.json', 0640);

        $env = '';
        foreach ($plan['platforms'] as $platform) {
            $env .= strtoupper($platform) . "_TOKEN=" . $tokens[$platform] . "\n";
        }
        $env .= "DB_HOST=127.0.0.1\nDB_PORT=3307\nDB_NAME=modasr_hosting_factory_staging\nDB_USER=modasrfactory\nDB_PASSWORD=" . getenv('FACTORY_DB_PASSWORD') . "\n";
        $env .= 'MODASR_OWNER_TELEGRAM_ID=' . (string) getenv('MODASR_OWNER_TELEGRAM_ID') . "\n";
        file_put_contents($root . '/.env', $env, LOCK_EX);
        chmod($root . '/.env', 0600);
        if (!copy('/var/www/modasr-hosting-tenants/worker-template.php', $root . '/worker.php')) {
            throw new RuntimeException('runtime_template_failed');
        }
        chmod($root . '/worker.php', 0644);

        $backupStatus = null;
        if (!empty($bundle['mirza_backup']) && in_array('telegram', $plan['platforms'], true)) {
            try {
                factoryQuarantineMirzaBackup($factoryId, $bundle['mirza_backup']);
                $backupStatus = 'quarantined';
            } catch (Throwable) {
                $backupStatus = 'quarantine_failed';
            }
            $tenant['backup']['status'] = $backupStatus;
            file_put_contents($root . '/tenant.json', json_encode($tenant, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), LOCK_EX);
            chmod($root . '/tenant.json', 0640);
        }

        // Deliberately no spawner/systemd call: the tenant remains stopped
        // until the root owner explicitly starts it from the Factory panel.
        return ['factory_id' => $factoryId, 'service' => $service, 'status' => 'stopped', 'backup_status' => $backupStatus];
    } finally {
        factoryReleaseLock($pdo, $lockName);
    }
}

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
    factoryLog($pdo, $adminId, 'runtime_' . $action, $bot['bot_username'], $status);
}

$update = json_decode((string) file_get_contents('php://input'), true);
$isCallback = isset($update['callback_query']['message']);
if (!is_array($update) || (!$isCallback && !isset($update['message']))) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['handled' => false]);
    return;
}
$message = $isCallback ? $update['callback_query']['message'] : $update['message'];
$actor = $update['callback_query']['from'] ?? $message['from'];
$ownerId = (string) ($actor['id'] ?? '');
$chatId = $message['chat']['id'] ?? null;
$text = trim((string) ($update['callback_query']['data'] ?? $message['text'] ?? ''));
$normalized = strtolower($text);
if ($ownerId === '' || $chatId === null) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['handled' => false]);
    return;
}

$ownerTelegramId = (string) getenv('MODASR_OWNER_TELEGRAM_ID');
$knownCommands = [
    '/start', '/factory', '/create', '/testbot', '/mybots', '/wallet', '/account',
    '/notifications', '/settings', '/tutorial', '/support', '/plans', '/myorders', '/cancel', '/admin', '/allbots', '/health', '/customers', '/orders', '/logs', '/backup', '/setlimit', '/setprice', '/clearcard', '/newflow', '/flows', '/templates', '/jobs',
    'factory_type_vpn', 'factory_type_panel', 'factory_confirm', 'بات ساز', 'ربات ساز', 'ربات‌های من', 'ربات های من', 'لغو',
];

factoryEnsureSchema($pdo);
factoryWorkflowEnsureSchema($pdo);
factoryRegisterCustomer($pdo, $ownerId);
$pending = factoryPending($pdo, $ownerId);
$isRuntimeAction = preg_match('/^(stopbot|startbot)_\d+$/', $normalized) === 1 || preg_match('/^\/(markpaid|rejectpay)_\d+$/', $normalized) === 1 || preg_match('/^factory_(receipt|cancelorder)_\d+$/', $normalized) === 1 || preg_match('/^factory_platform_[trs+]+$/', $normalized) === 1 || preg_match('/^factory_backup_(upload|skip)$/', $normalized) === 1 || preg_match('/^\/usetemplate_(welcome|report|followup)$/', $normalized) === 1 || str_starts_with($normalized, '/setlimit_') || str_starts_with($normalized, '/setprice') || str_starts_with($normalized, '/setcard_') || preg_match('/^\/(validate|preview|publish|rollback|run|flowinfo|archive)_\d+$/', $normalized) === 1;
if ($pending === null && !in_array($normalized, $knownCommands, true) && !$isRuntimeAction) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['handled' => false]);
    return;
}

$isOwner = $ownerTelegramId !== '' && hash_equals($ownerTelegramId, $ownerId);
if (isset($update['callback_query']['id'])) {
    factoryApi((string) getenv('TELEGRAM_TOKEN'), 'answerCallbackQuery', [
        'callback_query_id' => $update['callback_query']['id'],
    ]);
}

try {
    if ($normalized === '/templates') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $keyboard = ['inline_keyboard' => [
                [['text' => '👋 خوش‌آمد سریع', 'callback_data' => '/usetemplate_welcome']],
                [['text' => '📊 گزارش سفارش‌ها', 'callback_data' => '/usetemplate_report']],
                [['text' => '⏱ پیگیری زمان‌دار', 'callback_data' => '/usetemplate_followup']],
                [['text' => '⬅️ بازگشت', 'callback_data' => '/admin']],
            ]];
            factoryReply($chatId, "🧩 <b>قالب‌های آماده Workflow</b>\n\nقالب به‌صورت Draft ساخته می‌شود و هیچ پیام یا اقدام واقعی اجرا نمی‌کند. ابتدا Validation و Preview و سپس Publish کن.", $keyboard);
        }
    } elseif (preg_match('/^\/usetemplate_(welcome|report|followup)$/', $normalized, $matches)) {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $template = $matches[1];
            $name = $template . '_' . time();
            $draft = factoryWorkflowTemplate($template, $name);
            [$valid, $errors] = factoryWorkflowValidate($draft);
            if (!$valid) {
                throw new RuntimeException('template_invalid');
            }
            $stmt = $pdo->prepare('INSERT INTO factory_flow (owner_id,name,status,draft_json) VALUES (?, ?, "draft", ?)');
            $stmt->execute([$ownerId, $name, json_encode($draft, JSON_UNESCAPED_UNICODE)]);
            $flowId = (int) $pdo->lastInsertId();
            factoryLog($pdo, $ownerId, 'workflow_template_created', null, 'flow=' . $flowId . ';template=' . $template);
            $keyboard = ['inline_keyboard' => [
                [['text' => '🔍 Preview امن', 'callback_data' => '/preview_' . $flowId]],
                [['text' => '🚀 Publish', 'callback_data' => '/publish_' . $flowId]],
                [['text' => '📂 ورک‌فلوها', 'callback_data' => '/flows']],
            ]];
            factoryReply($chatId, "✅ قالب <b>{$template}</b> با شناسه #{$flowId} ساخته شد.\n\nوضعیت: Draft\nاقدام واقعی پیش‌فرض: غیرفعال\n\nابتدا Preview و سپس Publish کن.", $keyboard);
        }
    } elseif ($normalized === '/newflow') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $name = 'flow_' . time();
            $draft = factoryDefaultWorkflow($name);
            $stmt = $pdo->prepare('INSERT INTO factory_flow (owner_id,name,status,draft_json) VALUES (?, ?, "draft", ?)');
            $stmt->execute([$ownerId, $name, json_encode($draft, JSON_UNESCAPED_UNICODE)]);
            $flowId = (int) $pdo->lastInsertId();
            factoryLog($pdo, $ownerId, 'workflow_created', null, 'flow=' . $flowId);
            $keyboard = ['inline_keyboard' => [
                [['text' => '✅ اعتبارسنجی', 'callback_data' => '/validate_' . $flowId]],
                [['text' => '🔍 Preview', 'callback_data' => '/preview_' . $flowId]],
                [['text' => '🚀 Publish', 'callback_data' => '/publish_' . $flowId]],
                [['text' => '📂 ورک‌فلوها', 'callback_data' => '/flows']],
            ]];
            factoryReply($chatId, "🧩 <b>ورک‌فلو #{$flowId} ساخته شد</b>\n\nNodes: Start → Message → Condition → Database → Delay → End\n\nبرای اعتبارسنجی و Preview و Publish، دکمه را انتخاب کن.", $keyboard);
        }
    } elseif ($normalized === '/flows') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $stmt = $pdo->prepare('SELECT id,name,status,active_version_id FROM factory_flow WHERE owner_id = ? ORDER BY id DESC LIMIT 12');
            $stmt->execute([$ownerId]);
            $rows = $stmt->fetchAll();
            if (!$rows) {
                factoryReply($chatId, 'هنوز ورک‌فلویی ساخته نشده است. برای ساخت /newflow را بفرست.', factoryMenu());
            } else {
                $lines = ["🧩 <b>ورک‌فلوهای شما:</b>"];
                $keyboard = ['inline_keyboard' => []];
                foreach ($rows as $row) {
                    $lines[] = '#' . $row['id'] . ' ' . $row['name'] . ' — ' . $row['status'];
                    $keyboard['inline_keyboard'][] = [['text' => 'ℹ️ #' . $row['id'], 'callback_data' => '/flowinfo_' . $row['id']]];
                }
                $keyboard['inline_keyboard'][] = [['text' => '➕ ورک‌فلو جدید', 'callback_data' => '/newflow']];
                factoryReply($chatId, implode("\n", $lines), $keyboard);
            }
        }
    } elseif ($normalized === '/jobs') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $completed = factoryWorkflowDueJobs($pdo, $ownerId);
            $count = count($completed);
            factoryReply($chatId, "⏱ {$count} job آماده اجرا انجام شد و ادامه دادند.", factoryMenu());
        }
    } elseif (preg_match('/^\/(validate|preview|publish|rollback|run|flowinfo|archive)_(\d+)$/', $normalized, $matches)) {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $action = $matches[1];
            $flowId = (int) $matches[2];
            $flow = factoryWorkflowGet($pdo, $flowId, $ownerId);
            if (!$flow) {
                factoryReply($chatId, 'ورک‌فلو پیدا نشد.', factoryMenu());
            } else {
                switch ($action) {
                    case 'validate':
                        $workflow = json_decode((string) $flow['draft_json'], true);
                        [$valid, $errors] = factoryWorkflowValidate($workflow);
                        if ($valid) {
                            factoryReply($chatId, '✅ ورک‌فلو از نظر schema و محدودیت‌ها معتبر است و آماده Preview/Publish است.', factoryMenu());
                        } else {
                            factoryReply($chatId, '❌ خطاهای ورک‌فلو: ' . implode('، ', $errors), factoryMenu());
                        }
                        break;
                    case 'preview':
                        $result = factoryWorkflowExecute($pdo, $flowId, $ownerId, 'preview-' . $flow['id'], true);
                        $log = implode("\n", $result['context']['log'] ?? []);
                        factoryReply($chatId, "🔍 <b>نتیجه Preview</b>\n\n" . $log, factoryMenu());
                        break;
                    case 'publish':
                        factoryWorkflowPublish($pdo, $flowId, $ownerId);
                        factoryReply($chatId, '🚀 ورک‌فلو با موفقیت Publish شد و نسخه‌دار شد. اکنون می‌توانید /run_' . $flowId . ' را اجرا کنید.', factoryMenu());
                        break;
                    case 'rollback':
                        factoryWorkflowRollback($pdo, $flowId, $ownerId);
                        factoryReply($chatId, '↩️ ورک‌فلو به نسخه قبلی بازگردانی شد. تاریخچه نسخه‌ها حذف نشده است.', factoryMenu());
                        break;
                    case 'run':
                        $result = factoryWorkflowExecute($pdo, $flowId, $ownerId, 'run-' . $flow['id'] . '-' . time(), false);
                        $log = implode("\n", $result['context']['log'] ?? []);
                        factoryReply($chatId, "🏁 <b>نتیجه اجرای ورک‌فلو</b>\n\n" . $log, factoryMenu());
                        break;
                    case 'flowinfo':
                        $versionId = (int) ($flow['active_version_id'] ?? 0);
                        $versionNumber = $versionId > 0 ? (int) $pdo->query('SELECT version_number FROM factory_flow_version WHERE id = ' . $versionId)->fetchColumn() : 0;
                        $info = "🧩 <b>ورک‌فلو #{$flowId}</b>\n\nنام: {$flow['name']}\nوضعیت: {$flow['status']}\nنسخه فعال: {$versionNumber}\nStart: {$flow['draft_json']}";
                        factoryReply($chatId, $info, factoryMenu());
                        break;
                    case 'archive':
                        factoryWorkflowArchive($pdo, $flowId, $ownerId);
                        factoryReply($chatId, '📦 ورک‌فلو بایگانی شد و دیگر اجرا نمی‌شود. تاریخچه آن حذف نشده است.', factoryMenu());
                        break;
                }
            }
        }
    } elseif ($normalized === '/admin') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $keyboard = ['inline_keyboard' => [
                [['text' => '🤖 همه ربات‌ها', 'callback_data' => '/allbots']],
                [['text' => '👥 مشتری‌ها', 'callback_data' => '/customers']],
                [['text' => '🧾 سفارش‌ها و رسیدها', 'callback_data' => '/orders']],
                [['text' => '🧩 قالب‌های Workflow', 'callback_data' => '/templates']],
                [['text' => '📡 سلامت سرویس‌ها', 'callback_data' => '/health']],
                [['text' => '📋 لاگ‌های عملیاتی', 'callback_data' => '/logs']],
                [['text' => '💾 Backup امن', 'callback_data' => '/backup']],
                [['text' => '⚙️ تنظیمات و محدودیت‌ها', 'callback_data' => '/settings']],
                [['text' => '⬅️ بازگشت', 'callback_data' => '/factory']],
            ]];
            factoryReply($chatId, "👑 <b>پنل مدیریت مالک</b>\n\nاز این بخش می‌توانی همه مشتری‌ها، ربات‌ها و سرویس‌ها را مدیریت کنی.", $keyboard);
        }
    } elseif ($normalized === '/allbots') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $stmt = $pdo->query('SELECT id, owner_id, bot_name, bot_username, status FROM factory_bot ORDER BY id DESC LIMIT 20');
            $rows = $stmt->fetchAll();
            if (!$rows) {
                factoryReply($chatId, 'هنوز رباتی در Bot Factory ثبت نشده است.', factoryMenu());
            } else {
                $lines = ["👑 <b>همه ربات‌های Bot Factory:</b>"];
                $keyboard = ['inline_keyboard' => []];
                foreach ($rows as $row) {
                    $lines[] = '#' . $row['id'] . ' ' . $row['bot_name'] . ' (@' . $row['bot_username'] . ') — ' . $row['status'];
                    if ($row['status'] === 'active') {
                        $keyboard['inline_keyboard'][] = [['text' => '⏸ توقف #' . $row['id'], 'callback_data' => 'stopbot_' . $row['id']]];
                    } else {
                        $keyboard['inline_keyboard'][] = [['text' => '▶️ راه‌اندازی #' . $row['id'], 'callback_data' => 'startbot_' . $row['id']]];
                    }
                }
                $keyboard['inline_keyboard'][] = [['text' => '⬅️ بازگشت', 'callback_data' => '/admin']];
                factoryReply($chatId, implode("\n", $lines), $keyboard);
            }
        }
    } elseif ($normalized === '/customers') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $stmt = $pdo->query('SELECT c.owner_id, c.last_seen_at, COUNT(DISTINCT b.id) AS bot_count, COUNT(DISTINCT o.id) AS order_count FROM factory_customer c LEFT JOIN factory_bot b ON b.owner_id = c.owner_id LEFT JOIN factory_order o ON o.owner_id = c.owner_id GROUP BY c.owner_id, c.last_seen_at ORDER BY c.last_seen_at DESC LIMIT 20');
            $rows = $stmt->fetchAll();
            if (!$rows) {
                factoryReply($chatId, 'هنوز مشتری ثبت نشده است.', factoryMenu());
            } else {
                $lines = ["👥 <b>مشتری‌های Bot Factory:</b>"];
                foreach ($rows as $row) {
                    $lines[] = '• <code>' . $row['owner_id'] . '</code> — ربات‌ها: ' . $row['bot_count'] . ' | سفارش‌ها: ' . $row['order_count'];
                }
                factoryReply($chatId, implode("\n", $lines), factoryMenu());
            }
        }
    } elseif ($normalized === '/orders') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $stmt = $pdo->query('SELECT id, bot_name, amount_toman, status, created_at FROM factory_order ORDER BY id DESC LIMIT 20');
            $rows = $stmt->fetchAll();
            if (!$rows) {
                factoryReply($chatId, 'هنوز سفارشی ثبت نشده است.', factoryMenu());
            } else {
                $lines = ["🧾 <b>سفارش‌های Bot Factory:</b>"];
                $keyboard = ['inline_keyboard' => []];
                foreach ($rows as $row) {
                    $amount = (int) $row['amount_toman'] === 0 ? 'رایگان' : number_format((int) $row['amount_toman']) . ' تومان';
                    $lines[] = '#' . $row['id'] . ' ' . $row['bot_name'] . ' — ' . $amount . ' — ' . $row['status'];
                    if ((string) $row['status'] === 'awaiting_payment' && (int) $row['amount_toman'] > 0) {
                        $keyboard['inline_keyboard'][] = [
                            ['text' => '✅ تأیید #' . $row['id'], 'callback_data' => '/markpaid_' . $row['id']],
                            ['text' => '❌ رد #' . $row['id'], 'callback_data' => '/rejectpay_' . $row['id']],
                        ];
                    }
                }
                factoryReply($chatId, implode("\n", $lines), $keyboard['inline_keyboard'] ? $keyboard : factoryMenu());
            }
        }
    } elseif ($normalized === '/logs') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $stmt = $pdo->query('SELECT action, bot_username, detail, created_at FROM factory_event ORDER BY id DESC LIMIT 10');
            $rows = $stmt->fetchAll();
            if (!$rows) {
                factoryReply($chatId, 'هنوز رویدادی ثبت نشده است.', factoryMenu());
            } else {
                $lines = ["📋 <b>لاگ‌های عملیاتی:</b>"];
                foreach ($rows as $row) {
                    $detail = $row['detail'] !== null && $row['detail'] !== '' ? ' — ' . $row['detail'] : '';
                    $lines[] = '• ' . $row['created_at'] . ' — ' . $row['action'] . $detail;
                }
                factoryReply($chatId, implode("\n", $lines), factoryMenu());
            }
        }
    } elseif ($normalized === '/backup') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $output = [];
            $code = 0;
            exec('sudo -n /usr/local/sbin/modasr-factory-backup 2>&1', $output, $code);
            if ($code !== 0) {
                throw new RuntimeException('backup_failed');
            }
            $result = implode("\n", $output);
            factoryLog($pdo, $ownerId, 'backup_created', null, $result);
            factoryReply($chatId, "💾 <b>Backup رمزنگاری‌شده آماده شد</b>\n\n" . $result, factoryMenu());
        }
    } elseif (preg_match('/^\/setlimit_(customer|global)_(\d+)$/', $normalized, $matches)) {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $scope = $matches[1];
            $value = (int) $matches[2];
            $key = $scope === 'customer' ? 'customer_bot_limit' : 'global_bot_limit';
            $min = $scope === 'customer' ? 1 : 5;
            $max = $scope === 'customer' ? 5 : 20;
            if ($value < $min || $value > $max) {
                factoryReply($chatId, "مقدار مجاز برای این محدودیت بین {$min} و {$max} است.", factoryMenu());
            } else {
                factorySetSetting($pdo, $key, (string) $value, $ownerId);
                factoryLog($pdo, $ownerId, 'limit_updated', null, $key . '=' . $value);
                factoryReply($chatId, "محدودیت {$key} روی {$value} تنظیم شد.", factoryMenu());
            }
        }
    } elseif (preg_match('/^\/setcard_(\d{12,19})$/', $normalized, $matches)) {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            factorySetSetting($pdo, 'payment_card_number', $matches[1], $ownerId);
            factoryLog($pdo, $ownerId, 'payment_card_updated');
            factoryReply($chatId, 'شماره کارت پرداخت با انتهای ' . substr($matches[1], -4) . ' ثبت شد. از این پس در سفارش‌های پولی به مشتری نمایش داده می‌شود.', factoryMenu());
        }
    } elseif ($normalized === '/clearcard') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            factorySetSetting($pdo, 'payment_card_number', '', $ownerId);
            factoryLog($pdo, $ownerId, 'payment_card_cleared');
            factoryReply($chatId, 'شماره کارت از پیام سفارش‌ها حذف شد.', factoryMenu());
        }
    } elseif (preg_match('/^\/setprice_(\d+)$/', $normalized, $matches)) {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $price = (int) $matches[1];
            if ($price < 0 || $price > 1000000000) {
                factoryReply($chatId, 'قیمت باید بین ۰ و ۱٬۰۰۰٬۰۰۰٬۰۰۰ تومان باشد.', factoryMenu());
            } else {
                factorySetSetting($pdo, 'vpn_price_toman', (string) $price, $ownerId);
                factoryLog($pdo, $ownerId, 'price_updated', null, 'vpn=' . $price);
                $label = $price === 0 ? 'رایگان' : number_format($price) . ' تومان';
                factoryReply($chatId, "قیمت ربات VPN روی {$label} تنظیم شد. سفارش‌های جدید با همین مبلغ ثبت می‌شوند و سفارش پولی تا تأیید پرداخت، ربات نمی‌سازد.", factoryMenu());
            }
        }
    } elseif ($normalized === '/setprice') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $price = factoryPriceToman($pdo);
            $label = $price === 0 ? 'رایگان' : number_format($price) . ' تومان';
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => 'رایگان', 'callback_data' => '/setprice_0'],
                    ['text' => '۱۰۰ هزار', 'callback_data' => '/setprice_100000'],
                ],
                [
                    ['text' => '۳۰۰ هزار', 'callback_data' => '/setprice_300000'],
                    ['text' => '۵۰۰ هزار', 'callback_data' => '/setprice_500000'],
                ],
                [
                    ['text' => '۱ میلیون', 'callback_data' => '/setprice_1000000'],
                    ['text' => '⬅️ بازگشت', 'callback_data' => '/settings'],
                ],
            ]];
            factoryReply($chatId, "💳 <b>تنظیم قیمت ربات VPN</b>\n\nقیمت فعلی: {$label}\n\nبرای تغییر قیمت، یک گزینه را انتخاب کن یا /setprice_مبلغ را بفرست. مبلغ هر سفارش هنگام ثبت، سمت سرور ذخیره و هنگام تأیید پرداخت کنترل می‌شود.", $keyboard);
        }
    } elseif ($normalized === '/health') {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $php = trim((string) shell_exec('systemctl is-active modasr-hosting-full-php'));
            $relay = trim((string) shell_exec('systemctl is-active modasr-hosting-staging'));
            $mysql = trim((string) shell_exec("docker inspect --format '{{.State.Status}}' modasr-hosting-mysql") ?: 'unknown');
            $disk = trim((string) shell_exec("df -h / | tail -n 1") ?: 'unknown');
            $stmt = $pdo->query("SELECT COUNT(*) FROM factory_bot WHERE status = 'active'");
            $activeBots = (int) $stmt->fetchColumn();
            $stmt = $pdo->query("SELECT COUNT(*) FROM factory_bot WHERE status = 'failed'");
            $failedBots = (int) $stmt->fetchColumn();
            $stmt = $pdo->query("SELECT COUNT(*) FROM factory_creation WHERE expires_at > " . time());
            $pendingBots = (int) $stmt->fetchColumn();
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM factory_event WHERE action = 'factory_error' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");
            $stmt->execute();
            $recentErrors = (int) $stmt->fetchColumn();
            factoryReply($chatId, "📡 <b>سلامت سرویس‌ها</b>\n\nPHP: {$php}\nRelay: {$relay}\nMySQL container: {$mysql}\nربات‌های فعال: {$activeBots}\nربات‌های ناموفق: {$failedBots}\nدرخواست‌های pending: {$pendingBots}\nخطاهای ۲۴ ساعت اخیر: {$recentErrors}\nفضای دیسک: {$disk}", factoryMenu());
        }
    } elseif (preg_match('/^\/rejectpay_(\d+)$/', $normalized, $matches)) {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $orderId = (int) $matches[1];
            $stmt = $pdo->prepare('SELECT * FROM factory_order WHERE id = ?');
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            if (!is_array($order)) {
                throw new RuntimeException('order_not_found');
            }
            $status = (string) $order['status'];
            if ($status === 'payment_rejected') {
                factoryReply($chatId, "رسید سفارش #{$orderId} قبلاً رد شده است؛ اقدام تکراری انجام نشد.", factoryMenu());
            } else {
                if (factoryPaymentTransition($status, 'reject') !== 'payment_rejected') {
                    throw new RuntimeException('payment_reject_invalid');
                }
                $update = $pdo->prepare('UPDATE factory_order SET status = "payment_rejected" WHERE id = ? AND status = "awaiting_payment"');
                $update->execute([$orderId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('order_transition_conflict');
                }
                factoryLog($pdo, $ownerId, 'payment_receipt_rejected', null, 'order=' . $orderId);
                factoryReply($chatId, "رسید سفارش #{$orderId} رد شد و مشتری برای ارسال مجدد مطلع شد.", factoryMenu());
                $keyboard = ['inline_keyboard' => [
                    [['text' => '📤 ارسال مجدد رسید', 'callback_data' => 'factory_receipt_' . $orderId]],
                    [['text' => '🧾 سفارش‌های من', 'callback_data' => '/myorders']],
                ]];
                factoryReply((string) $order['owner_id'], "❌ رسید سفارش #{$orderId} تأیید نشد.\n\nلطفاً رسید واضح و متناسب با مبلغ سفارش را دوباره ارسال کن یا با پشتیبانی تماس بگیر. ربات هنوز ساخته نشده است.", $keyboard);
            }
        }
    } elseif (preg_match('/^\/markpaid_(\d+)$/', $normalized, $matches)) {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $orderId = (int) $matches[1];
            $order = factoryClaimOrderPaid($pdo, $orderId);
            if (!empty($order['_payment_claimed'])) {
                factoryLog($pdo, $ownerId, 'payment_marked_paid', null, 'order=' . $orderId);
                factoryReply($chatId, "✅ پرداخت سفارش #{$orderId} تأیید شد. مشتری اکنون می‌تواند ساخت ربات را ادامه دهد.", factoryMenu());
                $customerKeyboard = ['inline_keyboard' => [
                    [['text' => '🤖 ادامه ساخت ربات', 'callback_data' => 'factory_confirm']],
                    [['text' => '🧾 سفارش‌های من', 'callback_data' => '/myorders']],
                ]];
                factoryReply((string) $order['owner_id'], "✅ پرداخت سفارش #{$orderId} تأیید شد.\n\nدکمه «ادامه ساخت ربات» را بزن تا Runtime ساخته شود. اگر زمان درخواست تمام شده باشد، با /factory مسیر را دوباره شروع کن؛ سفارش paid حفظ می‌شود.", $customerKeyboard);
            } else {
                factoryReply($chatId, "سفارش #{$orderId} قبلاً تأیید پرداخت شده است؛ عملیات تکراری انجام نشد.", factoryMenu());
            }
        }
    } elseif (preg_match('/^(stopbot|startbot)_(\d+)$/', $normalized, $matches)) {
        if (!$isOwner) {
            factoryReply($chatId, 'این بخش فقط برای مالک است.', factoryMenu());
        } else {
            $action = $matches[1] === 'stopbot' ? 'stop' : 'start';
            factoryRuntimeAction($pdo, (int) $matches[2], $action, $ownerId);
            factoryReply($chatId, 'وضعیت ربات به‌روزرسانی شد.', factoryMenu());
        }
    } elseif ($normalized === '/cancel' || $normalized === 'لغو') {
        factoryClearPending($pdo, $ownerId);
        factoryReply($chatId, 'درخواست ساخت ربات لغو شد. برای شروع دوباره /factory را بفرست.', factoryMenu());
    } elseif ($normalized === '/start' || $normalized === '/factory' || $normalized === 'بات ساز' || $normalized === 'ربات ساز') {
        factoryLog($pdo, $ownerId, 'open_factory');
        factoryReply($chatId, "💎 <b>MODASR Hosting Bot Factory</b>\n\nبه میزبانی ربات مدثر خوش آمدی.\n\nآیدی عددی شما: <code>{$ownerId}</code>\n\nاز این بخش می‌توانی ربات اختصاصی خودت را بسازی و روی هاست مدثر اجرا کنی.\nفعلاً فقط ربات نمایندگی VPN فعال است و هیچ اتصالی به پنل‌ها یا سایت‌های دیگر وجود ندارد.\n\nبرای شروع، «خرید و ساخت ربات اختصاصی» را انتخاب کن. سفارش پولی قبل از دریافت Token باید پرداخت و تأیید شود.", factoryMenu());
    } elseif ($normalized === '/create' || $normalized === '/testbot') {
        factorySavePending($pdo, $ownerId, 'type', null, null, 'vpn');
        $resetCreation = $pdo->prepare('UPDATE factory_creation SET bot_name = NULL, token_ciphertext = NULL, plan_code = "vpn" WHERE owner_id = ?');
        $resetCreation->execute([$ownerId]);
        factoryLog($pdo, $ownerId, 'create_started');
        $price = factoryPriceToman($pdo);
        $priceLabel = number_format($price) . ' تومان';
        $keyboard = ['inline_keyboard' => [
            [['text' => '🤖 ربات نمایندگی VPN', 'callback_data' => 'factory_type_vpn']],
            [['text' => '🧩 ربات نمایندگی پنل — بعداً', 'callback_data' => 'factory_type_panel']],
            [['text' => '⬅️ انصراف و بازگشت', 'callback_data' => '/cancel']],
        ]];
        factoryReply($chatId, "🤖 <b>خرید ربات اختصاصی</b>\n\nنوع ربات را انتخاب کنید:\n\nربات نمایندگی VPN: هر پلتفرم {$priceLabel}\nربات نمایندگی پنل: غیرفعال\n\nپرداخت قبل از دریافت Token انجام می‌شود و Runtime بعد از ساخت خاموش است.", $keyboard);
    } elseif ($normalized === '/mybots' || $normalized === 'ربات‌های من' || $normalized === 'ربات های من') {
        $stmt = $pdo->prepare('SELECT bot_name, bot_username, status FROM factory_bot WHERE owner_id = ? ORDER BY id DESC LIMIT 20');
        $stmt->execute([$ownerId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            factoryReply($chatId, 'هنوز رباتی نساخته‌ای. برای شروع /create را بفرست.', factoryMenu());
        } else {
            $lines = ["📂 <b>ربات‌های شما:</b>"];
            foreach ($rows as $row) {
                $lines[] = '• ' . $row['bot_name'] . ' (@' . $row['bot_username'] . ') — ' . $row['status'];
            }
            factoryReply($chatId, implode("\n", $lines), factoryMenu());
        }
    } elseif ($normalized === '/myorders') {
        $stmt = $pdo->prepare('SELECT id, bot_name, amount_toman, status, created_at FROM factory_order WHERE owner_id = ? ORDER BY id DESC LIMIT 10');
        $stmt->execute([$ownerId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            factoryReply($chatId, 'هنوز سفارشی نداری. برای ساخت ربات، «خرید ربات اختصاصی» را انتخاب کن.', factoryMenu());
        } else {
            $lines = ["🧾 <b>سفارش‌های شما:</b>"];
            $keyboard = ['inline_keyboard' => []];
            foreach ($rows as $row) {
                $status = (string) $row['status'];
                $amount = (int) $row['amount_toman'] === 0 ? 'رایگان' : number_format((int) $row['amount_toman']) . ' تومان';
                $lines[] = '#' . $row['id'] . ' — ' . $row['bot_name'] . "\n" . $amount . ' — ' . factoryOrderStatusLabel($status);
                if (in_array($status, ['awaiting_payment', 'payment_rejected'], true)) {
                    $keyboard['inline_keyboard'][] = [
                        ['text' => '📤 رسید #' . $row['id'], 'callback_data' => 'factory_receipt_' . $row['id']],
                        ['text' => '❌ لغو #' . $row['id'], 'callback_data' => 'factory_cancelorder_' . $row['id']],
                    ];
                }
            }
            $keyboard['inline_keyboard'][] = [['text' => '🔄 به‌روزرسانی', 'callback_data' => '/myorders']];
            $keyboard['inline_keyboard'][] = [['text' => '⬅️ منوی اصلی', 'callback_data' => '/factory']];
            factoryReply($chatId, implode("\n\n", $lines), $keyboard);
        }
    } elseif ($normalized === '/wallet') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM factory_order WHERE owner_id = ?');
        $stmt->execute([$ownerId]);
        $count = (int) $stmt->fetchColumn();
        factoryReply($chatId, "💰 <b>سفارش‌ها و پرداخت</b>\n\nتعداد سفارش‌ها: {$count}\n\nساخت ربات پولی فقط پس از ثبت وضعیت paid انجام می‌شود. وضعیت awaiting_payment یا active_test مجوز ساخت نیست.", factoryMenu());
    } elseif ($normalized === '/account') {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM factory_bot WHERE owner_id = ?');
        $stmt->execute([$ownerId]);
        $count = (int) $stmt->fetchColumn();
        $role = $isOwner ? 'مالک Staging' : 'مشتری';
        factoryReply($chatId, "👤 <b>حساب کاربری</b>\n\nآیدی عددی: <code>{$ownerId}</code>\nنقش: {$role}\nتعداد ربات‌ها: {$count}", factoryMenu());
    } elseif ($normalized === '/notifications') {
        $stmt = $pdo->prepare('SELECT action, bot_username, created_at FROM factory_event WHERE owner_id = ? ORDER BY id DESC LIMIT 5');
        $stmt->execute([$ownerId]);
        $rows = $stmt->fetchAll();
        if (!$rows) {
            factoryReply($chatId, 'هنوز رویدادی ثبت نشده است.', factoryMenu());
        } else {
            $lines = ["🔔 <b>رویدادهای اخیر:</b>"];
            foreach ($rows as $row) {
                $lines[] = '• ' . $row['action'] . ($row['bot_username'] ? ' — @' . $row['bot_username'] : '');
            }
            factoryReply($chatId, implode("\n", $lines), factoryMenu());
        }
    } elseif ($normalized === '/settings') {
        if (!$isOwner) {
            factoryReply($chatId, 'تنظیمات مدیریتی فقط برای مالک است.', factoryMenu());
        } else {
            $customerLimit = factorySetting($pdo, 'customer_bot_limit', '2');
            $globalLimit = factorySetting($pdo, 'global_bot_limit', '10');
            $card = factorySetting($pdo, 'payment_card_number', '');
            $cardStatus = preg_match('/^\d{12,19}$/', $card) ? 'ثبت شده؛ انتهای ' . substr($card, -4) : 'ثبت نشده';
            $keyboard = ['inline_keyboard' => [
                [
                    ['text' => '💳 تغییر قیمت', 'callback_data' => '/setprice'],
                    ['text' => '🗑 حذف کارت', 'callback_data' => '/clearcard'],
                ],
                [
                    ['text' => '۱ ربات/مشتری', 'callback_data' => '/setlimit_customer_1'],
                    ['text' => '۲ ربات/مشتری', 'callback_data' => '/setlimit_customer_2'],
                    ['text' => '۳ ربات/مشتری', 'callback_data' => '/setlimit_customer_3'],
                ],
                [
                    ['text' => '۴ ربات/مشتری', 'callback_data' => '/setlimit_customer_4'],
                    ['text' => '۵ ربات/مشتری', 'callback_data' => '/setlimit_customer_5'],
                ],
                [
                    ['text' => '۱۰ ربات/کل محیط', 'callback_data' => '/setlimit_global_10'],
                    ['text' => '۲۰ ربات/کل محیط', 'callback_data' => '/setlimit_global_20'],
                ],
                [
                    ['text' => '💾 Backup امن', 'callback_data' => '/backup'],
                    ['text' => '📡 Health', 'callback_data' => '/health'],
                ],
                [
                    ['text' => '⬅️ بازگشت', 'callback_data' => '/admin'],
                ],
            ]];
            $price = factoryPriceToman($pdo);
            $priceLabel = $price === 0 ? 'رایگان' : number_format($price) . ' تومان';
            factoryReply($chatId, "⚙️ <b>تنظیمات و محدودیت‌ها</b>\n\nقیمت هر پلتفرم VPN: {$priceLabel}\nاجرای ربات: Long Polling\nقفل ساخت ربات پولی: paid اجباری\nکارت پرداخت: {$cardStatus}\nمحدودیت هر مشتری: {$customerLimit} ربات\nمحدودیت کل محیط: {$globalLimit} ربات\n\nبرای ثبت کارت، دستور /setcard_شماره‌کارت را بفرست. شماره کارت در لاگ ذخیره نمی‌شود.", $keyboard);
        }
    } elseif ($normalized === '/tutorial') {
        factoryReply($chatId, "🎫 <b>آموزش ساخت ربات</b>\n\n۱) ربات VPN و بسته تلگرام یا سروش را انتخاب کن.\n۲) نام فنی بساز و رسید پرداخت را ارسال کن.\n۳) Token هر پلتفرم را جداگانه ارسال کن.\n۴) برای تلگرام می‌توانی Backup میرزا را در قرنطینه بگذاری.\n۵) ربات کاملاً جدید و خاموش ساخته می‌شود؛ روشن‌کردن فقط توسط مالک اصلی است.\n\nبرای لغو در هر مرحله /cancel را بزن.", factoryMenu());
    } elseif ($normalized === '/support') {
        factoryReply($chatId, 'برای پشتیبانی Bot Factory با @modasrpanel در تماس باشید.', factoryMenu());
    } elseif (preg_match('/^factory_receipt_(\d+)$/', $normalized, $matches)) {
        $orderId = (int) $matches[1];
        $order = factoryOwnedOrder($pdo, $orderId, $ownerId);
        $status = (string) $order['status'];
        if (factoryPaymentTransition($status, 'submit_receipt') !== 'awaiting_payment') {
            throw new RuntimeException('payment_receipt_invalid');
        }
        if ($pending !== null && (string) $pending['bot_name'] !== (string) $order['bot_name']) {
            factoryReply($chatId, 'یک درخواست ساخت دیگر در حال انجام است. ابتدا آن را با /cancel لغو یا تکمیل کن و سپس رسید این سفارش را بفرست.', factoryMenu());
        } else {
            $stmt = $pdo->prepare('UPDATE factory_creation SET step = ?, expires_at = ? WHERE owner_id = ? AND bot_name = ?');
            $stmt->execute(['receipt_' . $orderId, time() + 7200, $ownerId, $order['bot_name']]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('creation_session_expired');
            }
            factoryReply($chatId, "📤 <b>ارسال رسید سفارش #{$orderId}</b>\n\nعکس رسید یا فایل تصویر/PDF تا ۱۰ مگابایت را همین‌جا ارسال کن. رسید فقط برای مالک ارسال می‌شود و در لاگ ذخیره نمی‌شود.\n\nبرای انصراف /cancel را بزن.");
        }
    } elseif (preg_match('/^factory_cancelorder_(\d+)$/', $normalized, $matches)) {
        $orderId = (int) $matches[1];
        $order = factoryOwnedOrder($pdo, $orderId, $ownerId);
        $status = (string) $order['status'];
        if ($status === 'cancelled') {
            factoryReply($chatId, "سفارش #{$orderId} قبلاً لغو شده است.", factoryMenu());
        } else {
            $next = factoryPaymentTransition($status, 'cancel');
            if ($next !== 'cancelled') {
                throw new RuntimeException('order_cancel_invalid');
            }
            $stmt = $pdo->prepare('UPDATE factory_order SET status = "cancelled" WHERE id = ? AND status = ?');
            $stmt->execute([$orderId, $status]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('order_transition_conflict');
            }
            if ($pending !== null && (string) $pending['bot_name'] === (string) $order['bot_name']) {
                factoryClearPending($pdo, $ownerId);
            }
            factoryLog($pdo, $ownerId, 'order_cancelled', null, 'order=' . $orderId);
            factoryReply($chatId, "❌ سفارش #{$orderId} لغو شد. چون پرداختی تأیید نشده بود، هیچ ربات یا تراکنش بازپرداختی ایجاد نشد.", factoryMenu());
        }
    } elseif ($normalized === '/plans') {
        $price = factoryPriceToman($pdo);
        $priceLabel = $price === 0 ? 'رایگان' : number_format($price) . ' تومان';
        $twoPlatforms = number_format($price * 2) . ' تومان';
        $threePlatforms = number_format($price * 3) . ' تومان';
        factoryReply($chatId, "💎 <b>پلن ربات نمایندگی VPN</b>\n\nتک‌پلتفرم: {$priceLabel}\nدوپلتفرم: {$twoPlatforms}\nسه‌پلتفرم: {$threePlatforms}\n\nقیمت از تعداد پلتفرم سمت سرور محاسبه می‌شود. ربات پس از ساخت کاملاً جدید و خاموش است و فقط مالک اصلی آن را روشن می‌کند.", factoryMenu());
    } elseif ($pending !== null && preg_match('/^receipt_(\d+)$/', (string) $pending['step'], $matches)) {
        $orderId = (int) $matches[1];
        $lockName = substr('factory_receipt_' . hash('sha256', $orderId . '|' . $ownerId), 0, 60);
        factoryAcquireLock($pdo, $lockName, 'receipt_lock_failed');
        try {
            $stateCheck = $pdo->prepare('SELECT step FROM factory_creation WHERE owner_id = ?');
            $stateCheck->execute([$ownerId]);
            if ((string) $stateCheck->fetchColumn() !== 'receipt_' . $orderId) {
                throw new RuntimeException('receipt_already_processed');
            }

            $order = factoryOwnedOrder($pdo, $orderId, $ownerId);
            $status = (string) $order['status'];
            if (factoryPaymentTransition($status, 'submit_receipt') !== 'awaiting_payment') {
                throw new RuntimeException('payment_receipt_invalid');
            }

            $method = null;
            $fileId = '';
            $fileSize = 0;
            if (!empty($message['photo']) && is_array($message['photo'])) {
                $photo = $message['photo'];
                $largest = end($photo);
                $fileId = is_array($largest) ? (string) ($largest['file_id'] ?? '') : '';
                $fileSize = is_array($largest) ? (int) ($largest['file_size'] ?? 0) : 0;
                $method = 'sendPhoto';
            } elseif (!empty($message['document']) && is_array($message['document'])) {
                $document = $message['document'];
                $mime = strtolower((string) ($document['mime_type'] ?? ''));
                if (str_starts_with($mime, 'image/') || $mime === 'application/pdf') {
                    $fileId = (string) ($document['file_id'] ?? '');
                    $fileSize = (int) ($document['file_size'] ?? 0);
                    $method = 'sendDocument';
                }
            }

            if ($method === null || $fileId === '') {
                factoryReply($chatId, 'فقط عکس رسید یا فایل تصویر/PDF قابل قبول است. لطفاً رسید را به‌صورت Photo یا Document بفرست.');
            } elseif ($fileSize > 10 * 1024 * 1024) {
                factoryReply($chatId, 'حجم رسید بیشتر از ۱۰ مگابایت است. لطفاً فایل کوچک‌تری ارسال کن.');
            } else {
                if ($ownerTelegramId === '') {
                    throw new RuntimeException('payment_reviewer_unavailable');
                }
                $amount = number_format((int) $order['amount_toman']) . ' تومان';
                $safeBotName = htmlspecialchars((string) $order['bot_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $caption = "🧾 <b>رسید سفارش #{$orderId}</b>\n\nمشتری: <code>{$ownerId}</code>\nربات: {$safeBotName}\nمبلغ: {$amount}\n\nپس از بررسی واقعی پرداخت، یک گزینه را انتخاب کن.";
                $keyboard = ['inline_keyboard' => [
                    [
                        ['text' => '✅ تأیید پرداخت', 'callback_data' => '/markpaid_' . $orderId],
                        ['text' => '❌ رد رسید', 'callback_data' => '/rejectpay_' . $orderId],
                    ],
                ]];
                $fileParam = $method === 'sendPhoto' ? 'photo' : 'document';
                try {
                    factoryApi((string) getenv('TELEGRAM_TOKEN'), $method, [
                        'chat_id' => $ownerTelegramId,
                        $fileParam => $fileId,
                        'caption' => $caption,
                        'parse_mode' => 'HTML',
                        'reply_markup' => json_encode($keyboard, JSON_UNESCAPED_UNICODE),
                    ]);
                } catch (RuntimeException) {
                    throw new RuntimeException('receipt_delivery_failed');
                }

                $updateOrder = $pdo->prepare('UPDATE factory_order SET status = "awaiting_payment" WHERE id = ? AND status IN ("awaiting_payment", "payment_rejected")');
                $updateOrder->execute([$orderId]);
                $nextStep = 'payment';
                if (!empty($pending['token_ciphertext'])) {
                    $bundle = json_decode(factoryDecrypt((string) $pending['token_ciphertext']), true);
                    if (is_array($bundle) && is_array($bundle['tokens'] ?? null)) {
                        $plan = factoryPlan((string) $pending['plan_code']);
                        $nextPlatform = factoryNextTokenPlatform($plan, $bundle);
                        $nextStep = $nextPlatform !== null ? 'token_' . $nextPlatform : (in_array('telegram', $plan['platforms'], true) ? 'backup_choice' : 'confirm');
                    }
                }
                $updateCreation = $pdo->prepare('UPDATE factory_creation SET step = ?, expires_at = ? WHERE owner_id = ? AND step = ?');
                $updateCreation->execute([$nextStep, time() + 86400, $ownerId, 'receipt_' . $orderId]);
                factoryLog($pdo, $ownerId, 'payment_receipt_submitted', null, 'order=' . $orderId);
                factoryReply($chatId, "✅ رسید سفارش #{$orderId} برای مالک ارسال شد.\n\nوضعیت: در انتظار بررسی\nبعد از تأیید پرداخت، پیام ادامه ساخت برایت ارسال می‌شود.", factoryMenu());
            }
        } finally {
            factoryReleaseLock($pdo, $lockName);
        }
    } elseif ($pending !== null && $pending['step'] === 'name') {
        $text = factoryNormalizeBotName($text);
        if ($text === '' || !preg_match('/^[a-z][a-z0-9_]{2,27}_bot$/', $text)) {
            factoryReply($chatId, 'نام فنی معتبر نیست. فقط حروف کوچک، عدد و _ استفاده کن و نام باید با حرف شروع و با _bot تمام شود. مثال: modasrhosting_bot');
        } else {
            $stmt = $pdo->prepare('SELECT id FROM factory_bot WHERE bot_name = ?');
            $stmt->execute([$text]);
            if ($stmt->fetch()) {
                factoryReply($chatId, 'این نام قبلاً استفاده شده؛ یک نام متفاوت انتخاب کن.');
            } else {
                $planCode = (string) $pending['plan_code'];
                $price = factoryPlanPriceToman($pdo, $planCode);
                $order = factoryCheckoutOrder($pdo, $ownerId, $text, $price, $planCode);
                $orderId = (int) $order['id'];
                factoryLog($pdo, $ownerId, 'name_selected', $text, 'order=' . $orderId);

                if (factoryOrderProvisionError($order, $ownerId) === null) {
                    $plan = factoryPlan($planCode);
                    $firstPlatform = factoryNextTokenPlatform($plan, ['tokens' => []]);
                    factorySavePending($pdo, $ownerId, 'token_' . $firstPlatform, $text);
                    $platformLabel = factoryCatalogPlatforms()[$firstPlatform];
                    factoryReply($chatId, "✅ پرداخت سفارش #{$orderId} قبلاً تأیید شده است.\n\n🔐 <b>دریافت Token {$platformLabel}</b>\n\nToken مربوط به {$platformLabel} را ارسال کن. پس از اعتبارسنجی موفق، پیام Token حذف می‌شود.");
                } else {
                    factorySavePending($pdo, $ownerId, 'payment', $text);
                    $extend = $pdo->prepare('UPDATE factory_creation SET expires_at = ? WHERE owner_id = ? AND step = "payment"');
                    $extend->execute([time() + 86400, $ownerId]);
                    $panel = factoryPaymentPanel($pdo, $order);
                    factoryReply($chatId, "💳 <b>ساخت ربات • مرحله ۲ از ۵</b>\n\n" . $panel['text'], $panel['keyboard']);
                }
            }
        }
    } elseif ($pending !== null && $pending['step'] === 'payment' && $normalized === 'factory_confirm') {
        $planCode = (string) $pending['plan_code'];
        $order = factoryCheckoutOrder($pdo, $ownerId, (string) $pending['bot_name'], factoryPlanPriceToman($pdo, $planCode), $planCode);
        if (factoryOrderProvisionError($order, $ownerId) === null) {
            $plan = factoryPlan($planCode);
            $bundle = ['tokens' => [], 'identities' => []];
            if (!empty($pending['token_ciphertext'])) {
                $decoded = json_decode(factoryDecrypt((string) $pending['token_ciphertext']), true);
                if (is_array($decoded) && is_array($decoded['tokens'] ?? null)) {
                    $bundle = $decoded;
                }
            }
            $firstPlatform = factoryNextTokenPlatform($plan, $bundle);
            if ($firstPlatform === null) {
                $nextStep = in_array('telegram', $plan['platforms'], true) ? 'backup_choice' : 'confirm';
                factorySavePending($pdo, $ownerId, $nextStep, (string) $pending['bot_name']);
                factoryReply($chatId, 'پرداخت و Tokenهای این بسته قبلاً کامل شده‌اند. برای ادامه، همان دکمه قبلی را بزن یا /factory را شروع کن.', factoryMenu());
            } else {
                factorySavePending($pdo, $ownerId, 'token_' . $firstPlatform, (string) $pending['bot_name']);
                factoryLog($pdo, $ownerId, 'payment_step_completed', null, 'order=' . $order['id']);
                $platformLabel = factoryCatalogPlatforms()[$firstPlatform];
                factoryReply($chatId, "✅ پرداخت سفارش #{$order['id']} تأیید شده است.\n\n🔐 <b>دریافت Token {$platformLabel}</b>\n\nToken مربوط به {$platformLabel} را ارسال کن. پس از اعتبارسنجی موفق، پیام Token حذف می‌شود.");
            }
        } else {
            $panel = factoryPaymentPanel($pdo, $order);
            factoryReply($chatId, "پرداخت هنوز تأیید نشده است.\n\n" . $panel['text'], $panel['keyboard']);
        }
    } elseif ($pending !== null && preg_match('/^token_(telegram|rubika|soroush)$/', (string) $pending['step'], $matches)) {
        $platform = $matches[1];
        $planCode = (string) $pending['plan_code'];
        $plan = factoryPlan($planCode);
        if (!in_array($platform, $plan['platforms'], true)) {
            throw new RuntimeException('credentials_invalid');
        }
        $order = factoryCheckoutOrder($pdo, $ownerId, (string) $pending['bot_name'], factoryPlanPriceToman($pdo, $planCode), $planCode);
        if (factoryOrderProvisionError($order, $ownerId) !== null) {
            factorySavePending($pdo, $ownerId, 'payment', (string) $pending['bot_name']);
            $panel = factoryPaymentPanel($pdo, $order);
            factoryReply($chatId, "قبل از ارسال Token باید پرداخت تأیید شود.\n\n" . $panel['text'], $panel['keyboard']);
        } else {
            $bundle = ['tokens' => [], 'identities' => []];
            if (!empty($pending['token_ciphertext'])) {
                $plain = factoryDecrypt((string) $pending['token_ciphertext']);
                $decoded = json_decode($plain, true);
                if (is_array($decoded) && is_array($decoded['tokens'] ?? null)) {
                    $bundle = $decoded;
                }
            }
            $text = factoryNormalizePlatformToken($platform, $text);
            $platformLabel = factoryCatalogPlatforms()[$platform];
            if (!factoryPlatformTokenValid($platform, $text)) {
                factoryReply($chatId, "قالب Token {$platformLabel} معتبر نیست. Token واقعی را بدون فاصله یا متن اضافه بفرست.");
            } else {
                $identity = factoryPlatformIdentity($platform, $text);
                if ($platform === 'telegram') {
                    $webhook = factoryPlatformRequest($platform, $text, 'getWebhookInfo');
                    if (!empty($webhook['url'])) {
                        factoryLog($pdo, $ownerId, 'webhook_conflict', $identity['username']);
                        throw new RuntimeException('telegram_webhook_conflict');
                    }
                }
                $bundle['tokens'][$platform] = $text;
                $bundle['identities'][$platform] = $identity;
                $credentialsJson = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                factoryApi((string) getenv('TELEGRAM_TOKEN'), 'deleteMessage', [
                    'chat_id' => $chatId,
                    'message_id' => (int) $message['message_id'],
                ]);
                factoryLog($pdo, $ownerId, 'platform_token_validated', $identity['username'], 'platform=' . $platform);

                $next = factoryNextTokenPlatform($plan, $bundle);
                if ($next !== null) {
                    factorySavePending($pdo, $ownerId, 'token_' . $next, (string) $pending['bot_name'], factoryEncrypt($credentialsJson));
                    $nextLabel = factoryCatalogPlatforms()[$next];
                    factoryReply($chatId, "✅ Token {$platformLabel} تأیید شد.\n\n🔐 اکنون Token <b>{$nextLabel}</b> را ارسال کن.");
                } else {
                    if (in_array('telegram', $plan['platforms'], true)) {
                        factorySavePending($pdo, $ownerId, 'backup_choice', (string) $pending['bot_name'], factoryEncrypt($credentialsJson));
                        $keyboard = ['inline_keyboard' => [
                            [['text' => '📦 آپلود Backup میرزا — قرنطینه', 'callback_data' => 'factory_backup_upload']],
                            [['text' => '⏭ بدون Backup و ادامه', 'callback_data' => 'factory_backup_skip']],
                        ]];
                        factoryReply($chatId, "✅ همه Tokenها تأیید شدند.\n\nبرای تلگرام می‌توانی Backup میرزا را ارسال کنی. Backup فقط قرنطینه می‌شود؛ هیچ SQL یا کد آن اجرا نمی‌شود و Import خودکار غیرفعال است.", $keyboard);
                    } else {
                        factorySavePending($pdo, $ownerId, 'confirm', (string) $pending['bot_name'], factoryEncrypt($credentialsJson));
                        $keyboard = ['inline_keyboard' => [[['text' => '✅ ساخت ربات در حالت خاموش', 'callback_data' => 'factory_confirm']]]];
                        factoryReply($chatId, "✅ Token سروش تأیید شد. اکنون ربات کاملاً جدید و در حالت خاموش ساخته می‌شود.", $keyboard);
                    }
                }
            }
        }
    } elseif ($pending !== null && $pending['step'] === 'type' && $normalized === 'factory_type_panel') {
        factoryReply($chatId, 'ربات نمایندگی پنل هنوز فعال نیست. در حال حاضر فقط ربات نمایندگی VPN قابل سفارش است.', factoryMenu());
    } elseif ($pending !== null && $pending['step'] === 'type' && $normalized === 'factory_type_vpn') {
        factorySavePending($pdo, $ownerId, 'platform');
        $base = number_format(factoryPriceToman($pdo));
        $keyboard = ['inline_keyboard' => [
            [
                ['text' => 'تلگرام تکی', 'callback_data' => 'factory_platform_t'],
                ['text' => 'روبیکا تکی', 'callback_data' => 'factory_platform_r'],
                ['text' => 'سروش تکی', 'callback_data' => 'factory_platform_s'],
            ],
            [
                ['text' => 'تلگرام + روبیکا', 'callback_data' => 'factory_platform_t+r'],
                ['text' => 'تلگرام + سروش', 'callback_data' => 'factory_platform_t+s'],
            ],
            [
                ['text' => 'روبیکا + سروش', 'callback_data' => 'factory_platform_r+s'],
                ['text' => 'هر سه پلتفرم', 'callback_data' => 'factory_platform_t+r+s'],
            ],
            [['text' => '⬅️ بازگشت', 'callback_data' => '/create']],
        ]];
        factoryReply($chatId, "🧩 <b>انتخاب بسته پلتفرم</b>\n\nهر پلتفرم {$base} تومان؛ دوپلتفرم دوبرابر و سه‌پلتفرم سه‌برابر محاسبه می‌شود.\n\nتک، دوتایی یا هر سه را انتخاب کن. روبیکا به Webhook HTTPS مستقل نیاز دارد.", $keyboard);
    } elseif ($pending !== null && $pending['step'] === 'platform' && preg_match('/^factory_platform_([trs+]+)$/', $normalized, $matches)) {
        $planCode = 'v:' . $matches[1];
        $plan = factoryPlan($planCode);
        if (in_array('rubika', $plan['platforms'], true)) {
            throw new RuntimeException('rubika_route_required');
        }
        factorySavePending($pdo, $ownerId, 'name', null, null, $planCode);
        $price = factoryPlanPriceToman($pdo, $planCode);
        $priceLabel = number_format($price) . ' تومان';
        $platformLabel = factoryPlanLabel($planCode);
        factoryReply($chatId, "🧩 <b>ساخت ربات • انتخاب نام</b>\n\nبسته: {$platformLabel}\nمبلغ سفارش: {$priceLabel}\n\nیک نام فنی انگلیسی انتخاب کن؛ با حرف شروع و با _bot تمام شود.\nمثال: modasrhosting_bot\n\nبرای لغو /cancel را بزن.");
    } elseif ($pending !== null && $pending['step'] === 'backup_choice' && $normalized === 'factory_backup_upload') {
        factorySavePending($pdo, $ownerId, 'mirza_backup');
        factoryReply($chatId, "📦 <b>ارسال Backup میرزا</b>\n\nفایل SQL یا ZIP تا ۵۰ مگابایت را به‌صورت Document ارسال کن.\nفایل فقط در قرنطینه Tenant ذخیره می‌شود؛ SQL اجرا و ZIP باز نمی‌شود. هیچ داده‌ای از ربات‌های مالک اصلی کپی نمی‌شود.");
    } elseif ($pending !== null && $pending['step'] === 'backup_choice' && $normalized === 'factory_backup_skip') {
        factorySavePending($pdo, $ownerId, 'confirm');
        $keyboard = ['inline_keyboard' => [[['text' => '✅ ساخت ربات در حالت خاموش', 'callback_data' => 'factory_confirm']]]];
        factoryReply($chatId, "بدون Backup ادامه می‌دهیم. ربات با داده کاملاً جدید و در حالت خاموش ساخته می‌شود.", $keyboard);
    } elseif ($pending !== null && $pending['step'] === 'mirza_backup') {
        $document = $message['document'] ?? null;
        $name = is_array($document) ? basename((string) ($document['file_name'] ?? '')) : '';
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $size = is_array($document) ? (int) ($document['file_size'] ?? 0) : 0;
        $fileId = is_array($document) ? (string) ($document['file_id'] ?? '') : '';
        if ($fileId === '' || !in_array($extension, ['sql', 'zip'], true) || $size <= 0 || $size > 50 * 1024 * 1024) {
            factoryReply($chatId, 'فایل معتبر نیست. فقط SQL یا ZIP تا ۵۰ مگابایت به‌صورت Document ارسال کن.');
        } else {
            $bundle = json_decode(factoryDecrypt((string) $pending['token_ciphertext']), true);
            if (!is_array($bundle) || !is_array($bundle['tokens'] ?? null)) {
                throw new RuntimeException('credentials_invalid');
            }
            $bundle['mirza_backup'] = [
                'file_id' => $fileId,
                'file_name' => $name,
                'file_size' => $size,
            ];
            $credentialsJson = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            factorySavePending($pdo, $ownerId, 'confirm', (string) $pending['bot_name'], factoryEncrypt($credentialsJson));
            factoryLog($pdo, $ownerId, 'mirza_backup_quarantine_requested', null, 'size=' . $size);
            $keyboard = ['inline_keyboard' => [[['text' => '✅ ساخت ربات خاموش و قرنطینه Backup', 'callback_data' => 'factory_confirm']]]];
            factoryReply($chatId, "✅ Backup ثبت شد و در مرحله ساخت، فقط در قرنطینه ذخیره می‌شود. هیچ Import یا SQL execution انجام نمی‌شود.", $keyboard);
        }
    } elseif ($pending !== null && $pending['step'] === 'confirm' && $normalized === 'factory_confirm') {
        $planCode = (string) $pending['plan_code'];
        $plan = factoryPlan($planCode);
        $order = factoryCheckoutOrder($pdo, $ownerId, (string) $pending['bot_name'], factoryPlanPriceToman($pdo, $planCode), $planCode);
        $orderId = (int) $order['id'];
        $orderAmount = (int) $order['amount_toman'];
        $orderStatus = (string) $order['status'];

        if (in_array($orderStatus, ['active', 'stopped'], true)) {
            $existing = $pdo->prepare('SELECT bot_username FROM factory_bot WHERE order_id = ?');
            $existing->execute([$orderId]);
            $existingUsername = (string) $existing->fetchColumn();
            factoryClearPending($pdo, $ownerId);
            factoryReply($chatId, "سفارش #{$orderId} قبلاً پردازش شده و ربات @{$existingUsername} ساخته شده است.\n\nوضعیت: {$orderStatus}\nساخت تکراری یا روشن‌کردن خودکار انجام نشد.", factoryMenu());
        } elseif (($orderAmount > 0 && $orderStatus !== 'paid') || ($orderAmount === 0 && $orderStatus !== 'free')) {
            $extend = $pdo->prepare('UPDATE factory_creation SET expires_at = ? WHERE owner_id = ? AND step = "confirm"');
            $extend->execute([time() + 86400, $ownerId]);
            factoryLog($pdo, $ownerId, 'payment_required', null, 'order=' . $orderId);
            $panel = factoryPaymentPanel($pdo, $order);
            factoryReply($chatId, $panel['text'], $panel['keyboard']);
        } else {
            $bundle = json_decode(factoryDecrypt((string) $pending['token_ciphertext']), true);
            if (!is_array($bundle) || !is_array($bundle['tokens'] ?? null) || !is_array($bundle['identities'] ?? null)) {
                throw new RuntimeException('credentials_invalid');
            }
            $runtime = factoryProvisionRuntime($pdo, $ownerId, (string) $orderId, (string) $pending['bot_name'], $bundle, $planCode);
            $updateOrder = $pdo->prepare('UPDATE factory_order SET status = "stopped" WHERE id = ? AND status IN ("paid", "free")');
            $updateOrder->execute([$orderId]);
            $createdNow = $updateOrder->rowCount() === 1;
            factoryClearPending($pdo, $ownerId);

            $primary = $plan['platforms'][0];
            $username = (string) $bundle['identities'][$primary]['username'];
            if ($createdNow) {
                factoryLog($pdo, $ownerId, 'bot_provisioned_stopped', $username, 'order=' . $orderId . ';tenant=' . $runtime['factory_id']);
                $backupText = match ($runtime['backup_status'] ?? null) {
                    'quarantined' => "\nBackup: قرنطینه شد؛ Import انجام نشد.",
                    'quarantine_failed' => "\nBackup: ذخیره قرنطینه ناموفق بود و نیاز به ارسال مجدد دارد.",
                    default => '',
                };
                factoryReply($chatId, "✅ <b>ربات کاملاً جدید ساخته شد</b>\n\nربات: @{$username}\nشماره سفارش: {$orderId}\nTenant: {$runtime['factory_id']}\nوضعیت: <b>خاموش</b>\n\nهیچ داده‌ای از ربات‌ها یا مشتری‌های مالک اصلی کپی نشده و هیچ سرویسی Start نشده است. مالک اصلی از /allbots آن را روشن می‌کند.{$backupText}", factoryMenu());
            } else {
                factoryReply($chatId, "این سفارش قبلاً پردازش شده و ربات @{$username} در حالت خاموش ساخته شده است. ساخت یا روشن‌کردن تکراری انجام نشد.\n\nسرویس: {$runtime['service']}", factoryMenu());
            }
        }
    }
} catch (RuntimeException $e) {
    factoryLog($pdo, $ownerId, 'factory_error', null, $e->getMessage());
    $error = $e->getMessage();
    $friendly = match (true) {
        $error === 'provider_unauthorized' => 'Token نامعتبر یا باطل‌شده است. در BotFather ربات را بررسی کن، Token را revoke و Token جدید بفرست.',
        $error === 'provider_unreachable' => 'اتصال به Telegram برای اعتبارسنجی Token برقرار نشد. چند لحظه دیگر دوباره تلاش کن.',
        str_starts_with($error, 'provider_') => 'اعتبارسنجی Token با Telegram انجام نشد. صحت Token را بررسی و دوباره بفرست.',
        $error === 'bot_already_exists' => 'این ربات قبلاً در Bot Factory ثبت شده و نمی‌توان دوباره ساخت.',
        $error === 'payment_required' => 'پرداخت این سفارش هنوز تأیید نشده است. ساخت ربات فقط پس از ثبت وضعیت paid انجام می‌شود.',
        $error === 'order_not_found' => 'سفارش موردنظر پیدا نشد.',
        $error === 'order_forbidden' => 'این سفارش متعلق به حساب شما نیست.',
        $error === 'order_invalid' => 'اطلاعات سفارش معتبر نیست؛ ساخت ربات متوقف شد.',
        $error === 'payment_claim_invalid' => 'فقط سفارش پولی awaiting_payment قابل تأیید پرداخت است.',
        $error === 'payment_claim_conflict' => 'وضعیت سفارش هم‌زمان تغییر کرد؛ دوباره بررسی کن.',
        $error === 'checkout_lock_failed' => 'ثبت سفارش هم‌زمان انجام نشد؛ چند لحظه بعد دوباره تلاش کن.',
        $error === 'runtime_lock_failed' => 'یک عملیات ساخت دیگر برای همین سفارش در حال انجام است.',
        $error === 'runtime_recovery_required' => 'برای این سفارش قبلاً Runtime ثبت شده اما نیاز به بررسی دارد؛ ساخت تکراری برای جلوگیری از اختلال متوقف شد.',
        $error === 'payment_receipt_invalid' => 'این سفارش در وضعیت فعلی امکان ارسال رسید ندارد.',
        $error === 'payment_reject_invalid' => 'فقط رسید در وضعیت awaiting_payment قابل رد است.',
        $error === 'payment_reviewer_unavailable' => 'گیرنده بررسی رسید تنظیم نشده است؛ با پشتیبانی تماس بگیر.',
        $error === 'creation_session_expired' => 'زمان ارسال رسید تمام شده یا درخواست ساخت وجود ندارد. از /factory شروع کن.',
        $error === 'receipt_lock_failed' => 'ارسال رسید دیگری در حال پردازش است؛ چند لحظه صبر کن.',
        $error === 'receipt_already_processed' => 'این رسید قبلاً پردازش شده است؛ ارسال تکراری انجام نشد.',
        $error === 'receipt_delivery_failed' => 'ارسال رسید به مالک انجام نشد و وضعیت سفارش تغییر نکرد. چند لحظه بعد دوباره ارسال کن.',
        $error === 'order_cancel_invalid' => 'این سفارش در وضعیت فعلی قابل لغو نیست.',
        $error === 'order_transition_conflict' => 'وضعیت سفارش هم‌زمان تغییر کرد؛ سفارش‌ها را به‌روزرسانی کن.',
        $error === 'template_not_found' => 'قالب Workflow پیدا نشد.',
        $error === 'template_invalid' => 'قالب Workflow اعتبارسنجی نشد و ساخته نشد.',
        $error === 'rubika_route_required' => 'برای روبیکا هنوز مسیر HTTPS مستقل راه‌اندازی نشده است. این بسته قبل از سفارش متوقف شد؛ ابتدا تلگرام یا سروش را انتخاب کن یا با پشتیبانی تماس بگیر.',
        $error === 'telegram_webhook_conflict' => 'ربات تلگرام در جای دیگری Webhook فعال دارد. ابتدا آن را غیرفعال کن و سپس Token را دوباره بفرست.',
        $error === 'plan_not_found' => 'بسته انتخاب‌شده معتبر نیست؛ از ابتدای خرید انتخاب کن.',
        $error === 'credentials_invalid' => 'اطلاعات اعتبارسنجی پلتفرم‌ها ناقص یا نامعتبر است؛ Tokenها را دوباره بررسی کن.',
        $error === 'credentials_incomplete' => 'Token همه پلتفرم‌های بسته هنوز کامل نیست؛ ساخت انجام نشد.',
        $error === 'order_plan_mismatch' => 'بسته سفارش با بسته ساخت ربات یکسان نیست؛ عملیات برای جلوگیری از تحویل اشتباه متوقف شد.',
        $error === 'tenant_status_failed' => 'تغییر وضعیت Tenant ثبت نشد و نیاز به بررسی مالک دارد.',
        $error === 'runtime_not_active' => 'سرویس ربات ساخته شد اما فعال‌سازی آن با خطا مواجه شد. وضعیت آن در پنل ثبت شد.',
        $error === 'customer_bot_limit' => 'در Staging هر مشتری حداکثر دو ربات فعال می‌تواند داشته باشد. ابتدا یکی از ربات‌های قبلی را متوقف کن.',
        $error === 'staging_capacity_full' => 'ظرفیت آزمایشی Bot Factory موقتاً پر است. بعداً دوباره تلاش کن یا با پشتیبانی تماس بگیر.',
        $error === 'bot_not_found' => 'ربات موردنظر در Bot Factory پیدا نشد.',
        $error === 'runtime_action_failed' => 'تغییر وضعیت سرویس ربات انجام نشد. سرویس دوباره بررسی شود.',
        $error === 'backup_failed' => 'ساخت Backup انجام نشد. ابتدا وضعیت دیسک و مجوزهای مسیر Backup بررسی شود.',
        default => 'ساخت ربات با خطای موقت مواجه شد. لطفاً دوباره تلاش کن یا با پشتیبانی تماس بگیر.',
    };
    factoryReply($chatId, $friendly, factoryMenu());
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['handled' => true]);
