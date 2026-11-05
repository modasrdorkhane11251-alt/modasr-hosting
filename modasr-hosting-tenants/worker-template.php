<?php

declare(strict_types=1);

set_time_limit(0);
ini_set('display_errors', '0');
error_reporting(E_ALL);

$root = getenv('TENANT_ROOT') ?: '/var/www/modasr-hosting-staging';
$envFile = $root . '/.env';
$tenantFile = $root . '/tenant.json';
if (!is_file($envFile) || !is_file($tenantFile)) {
    fwrite(STDERR, "missing_staging_configuration\n");
    exit(1);
}

$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$key, $value] = explode('=', $line, 2);
    $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
}

$tenant = json_decode((string) file_get_contents($tenantFile), true);
if (!is_array($tenant) || empty($tenant['id']) || !is_array($tenant['platforms'] ?? null)) {
    fwrite(STDERR, "invalid_staging_configuration\n");
    exit(1);
}

$platforms = array_values(array_intersect($tenant['platforms'], ['telegram', 'soroush']));
if ($platforms === []) {
    fwrite(STDOUT, "no_polling_platform\n");
    exit(0);
}
foreach ($platforms as $platform) {
    if (($env[strtoupper($platform) . '_TOKEN'] ?? '') === '') {
        fwrite(STDERR, "missing_platform_credential\n");
        exit(1);
    }
}

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $env['DB_HOST'] ?? 'localhost',
    $env['DB_PORT'] ?? '3306',
    $env['DB_NAME'] ?? 'modasr_auth_staging'
);
$pdo = new PDO($dsn, $env['DB_USER'] ?? '', $env['DB_PASSWORD'] ?? '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hosting_update_offset (
        tenant_id INT UNSIGNED PRIMARY KEY,
        last_update_id BIGINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);
$pdo->exec(
    'CREATE TABLE IF NOT EXISTS hosting_event (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        tenant_id INT UNSIGNED NOT NULL,
        update_id BIGINT NOT NULL,
        telegram_user_id VARCHAR(64) NULL,
        command VARCHAR(32) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_tenant_update (tenant_id, update_id),
        KEY idx_tenant_created (tenant_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

function tenantApi(string $platform, string $token, string $method, array $params = [], int $timeout = 35): array|bool
{
    $base = match ($platform) {
        'telegram' => 'https://api.telegram.org/bot',
        'soroush' => 'https://api.splus.ir/bot',
        default => throw new RuntimeException('platform_not_supported'),
    };
    $ch = curl_init($base . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $raw = curl_exec($ch);
    $error = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($error !== 0 || $raw === false) {
        throw new RuntimeException('telegram_transport_error');
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('telegram_invalid_response');
    }
    if (($decoded['ok'] ?? false) !== true) {
        throw new RuntimeException('telegram_api_error_' . $status);
    }
    return $decoded['result'] ?? [];
}

function tenantSend(string $platform, string $token, int|string $chatId, string $text, array $keyboard = []): void
{
    $payload = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard !== []) {
        $payload['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
    }
    tenantApi($platform, $token, 'sendMessage', $payload, 25);
}

function tenantMainMenu(): array
{
    return ['inline_keyboard' => [
        [['text' => '📡 وضعیت ربات', 'callback_data' => 'status']],
        [['text' => '🆔 آیدی من', 'callback_data' => 'id']],
        [['text' => '🛠 پشتیبانی', 'callback_data' => 'support']],
    ]];
}

function tenantPlatformIndex(string $platform): int
{
    return $platform === 'telegram' ? 1 : 2;
}

function tenantHandleUpdate(PDO $pdo, array $tenant, string $platform, string $token, array $update, int &$offset): void
{
    $updateId = (int) ($update['update_id'] ?? 0);
    if ($updateId <= 0 || $updateId < $offset) {
        return;
    }
    $message = $update['message'] ?? $update['callback_query']['message'] ?? null;
    $chatId = $message['chat']['id'] ?? null;
    $userId = $update['callback_query']['from']['id'] ?? $message['from']['id'] ?? null;
    if ($chatId === null) {
        $offset = $updateId + 1;
        return;
    }

    $incomingText = trim((string) ($update['message']['text'] ?? $update['callback_query']['data'] ?? ''));
    $command = strtolower($incomingText);
    $command = match (true) {
        str_starts_with($command, '/start') => '/start',
        str_starts_with($command, '/help') => '/help',
        str_starts_with($command, '/id') => '/id',
        str_starts_with($command, '/status') => '/status',
        $command === '/owner' => '/owner',
        $command === '/owner_users' => '/owner_users',
        $command === 'سلام' => '/start',
        $command === 'status' => '/status',
        $command === 'id' => '/id',
        $command === 'support' => '/support',
        default => 'unknown',
    };

    $platformIndex = tenantPlatformIndex($platform);
    $eventId = $platformIndex * 1000000000000000 + $updateId;
    $offsetKey = (int) $tenant['id'] * 10 + $platformIndex;
    $actorKey = $userId !== null ? $platform . ':' . $userId : null;
    $pdo->beginTransaction();
    try {
        $eventStmt = $pdo->prepare('INSERT IGNORE INTO hosting_event (tenant_id, update_id, telegram_user_id, command) VALUES (?, ?, ?, ?)');
        $eventStmt->execute([(int) $tenant['id'], $eventId, $actorKey, $platform . ':' . $command]);
        $newEvent = $eventStmt->rowCount() === 1;
        $offsetStmt = $pdo->prepare('INSERT INTO hosting_update_offset (tenant_id, last_update_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_update_id = GREATEST(last_update_id, VALUES(last_update_id))');
        $offsetStmt->execute([$offsetKey, $updateId + 1]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $offset = $updateId + 1;
    if (!$newEvent) {
        return;
    }

    if (isset($update['callback_query']['id'])) {
        tenantApi($platform, $token, 'answerCallbackQuery', ['callback_query_id' => $update['callback_query']['id']], 15);
    }
    $ownerId = (string) ($tenant['root_owner_ids'][$platform] ?? '');
    $isRootOwner = $ownerId !== '' && $userId !== null && hash_equals($ownerId, (string) $userId);

    switch ($command) {
        case '/start':
            $text = "💎 <b>MODASR Hosting</b>\n\nاین ربات با داده کاملاً جدید راه‌اندازی شده است.\nهیچ کاربر یا اطلاعاتی از ربات دیگری کپی نشده است.";
            tenantSend($platform, $token, $chatId, $text, tenantMainMenu());
            break;
        case '/help':
            tenantSend($platform, $token, $chatId, "دستورها:\n/start — شروع\n/help — راهنما\n/id — آیدی\n/status — وضعیت اتصال", tenantMainMenu());
            break;
        case '/id':
            tenantSend($platform, $token, $chatId, '🆔 آیدی شما در ' . $platform . ': <code>' . htmlspecialchars((string) $userId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>', tenantMainMenu());
            break;
        case '/status':
            tenantSend($platform, $token, $chatId, '✅ اتصال ' . $platform . ' فعال و به‌روزرسانی‌ها دریافت می‌شوند.', tenantMainMenu());
            break;
        case '/support':
            tenantSend($platform, $token, $chatId, 'برای پشتیبانی با @modasrpanel در تماس باشید.', tenantMainMenu());
            break;
        case '/owner':
            if ($isRootOwner) {
                $stmt = $pdo->prepare('SELECT COUNT(*) events, COUNT(DISTINCT telegram_user_id) users FROM hosting_event WHERE tenant_id = ?');
                $stmt->execute([(int) $tenant['id']]);
                $stats = $stmt->fetch() ?: ['events' => 0, 'users' => 0];
                $platformList = implode(', ', $tenant['platforms']);
                tenantSend($platform, $token, $chatId, "👑 <b>Owner Console — مخفی</b>\n\nTenant: <code>{$tenant['id']}</code>\nPlatforms: {$platformList}\nرویدادهای جدید: {$stats['events']}\nکاربران جدید: {$stats['users']}\n\nمدیریت Start/Stop فقط از /allbots در Factory انجام می‌شود.");
            }
            break;
        case '/owner_users':
            if ($isRootOwner) {
                $stmt = $pdo->prepare('SELECT DISTINCT telegram_user_id FROM hosting_event WHERE tenant_id = ? AND telegram_user_id IS NOT NULL ORDER BY telegram_user_id LIMIT 30');
                $stmt->execute([(int) $tenant['id']]);
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $text = $ids ? implode("\n", array_map(static fn($id): string => '<code>' . htmlspecialchars((string) $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</code>', $ids)) : 'هنوز کاربر جدیدی ثبت نشده است.';
                tenantSend($platform, $token, $chatId, "👑 کاربران این Tenant:\n\n" . $text);
            }
            break;
        default:
            tenantSend($platform, $token, $chatId, 'برای شروع /start و برای راهنما /help را بفرستید.', tenantMainMenu());
    }
}

$tokens = [];
$offsets = [];
$identities = [];
foreach ($platforms as $platform) {
    $tokens[$platform] = (string) $env[strtoupper($platform) . '_TOKEN'];
    $bot = tenantApi($platform, $tokens[$platform], 'getMe', [], 25);
    if ($platform === 'telegram') {
        $webhook = tenantApi($platform, $tokens[$platform], 'getWebhookInfo', [], 25);
        if (!empty($webhook['url'])) {
            fwrite(STDERR, "webhook_conflict_detected\n");
            exit(1);
        }
    }
    $identities[$platform] = (string) ($bot['username'] ?? $bot['first_name'] ?? $bot['id'] ?? '');
    tenantApi($platform, $tokens[$platform], 'setMyCommands', [
        'commands' => json_encode([
            ['command' => 'start', 'description' => 'شروع و معرفی ربات'],
            ['command' => 'help', 'description' => 'راهنمای استفاده'],
            ['command' => 'id', 'description' => 'نمایش آیدی'],
            ['command' => 'status', 'description' => 'وضعیت اتصال ربات'],
        ], JSON_UNESCAPED_UNICODE),
    ], 25);

    $offsetKey = (int) $tenant['id'] * 10 + tenantPlatformIndex($platform);
    $offsetStmt = $pdo->prepare('SELECT last_update_id FROM hosting_update_offset WHERE tenant_id = ?');
    $offsetStmt->execute([$offsetKey]);
    $offsets[$platform] = (int) ($offsetStmt->fetchColumn() ?: 0);
    if ($offsets[$platform] <= 0) {
        $offsets[$platform] = 1;
    }
    fwrite(STDOUT, "tenant_started platform={$platform} identity=@{$identities[$platform]}\n");
}

$backoff = array_fill_keys($platforms, 1);
while (true) {
    foreach ($platforms as $platform) {
        try {
            $updates = tenantApi($platform, $tokens[$platform], 'getUpdates', [
                'offset' => $offsets[$platform],
                'limit' => 50,
                'timeout' => 25,
                'allowed_updates' => json_encode(['message', 'callback_query']),
            ], 40);
            $backoff[$platform] = 1;
            foreach ($updates as $update) {
                tenantHandleUpdate($pdo, $tenant, $platform, $tokens[$platform], $update, $offsets[$platform]);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, "worker_retry platform={$platform} type=" . get_class($e) . "\n");
            sleep($backoff[$platform]);
            $backoff[$platform] = min(30, $backoff[$platform] * 2);
        }
    }
}
