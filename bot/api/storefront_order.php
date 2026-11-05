<?php
/**
 * MODASR Storefront Order Endpoint
 * --------------------------------
 * Receives orders from the MODASR website and registers them as pending
 * ("unpaid") service orders so the customer can pay inside the bot.
 *
 * Security model (defense in depth):
 *  - HMAC-SHA256 signature over "<timestamp>.<raw body>" with a shared secret
 *    stored in api/storefront_secret.php (NOT committed to git).
 *  - Timestamp freshness window (anti-replay).
 *  - Per-IP rate limiting (file based).
 *  - Idempotency: order_key must be unique (duplicate submissions rejected).
 *  - Prices are ALWAYS read from the database; client-sent prices are ignored.
 *  - All SQL uses prepared statements.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/storefront_core.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Asia/Tehran');

const STOREFRONT_MAX_CLOCK_SKEW = 300;      // seconds
const STOREFRONT_RATE_LIMIT = 10;           // orders per window per IP
const STOREFRONT_RATE_WINDOW = 3600;        // seconds
const STOREFRONT_MAX_ITEMS = 20;

function sf_respond(bool $ok, string $msg, array $obj = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['status' => $ok, 'msg' => $msg, 'obj' => $obj], JSON_UNESCAPED_UNICODE);
    exit;
}

function sf_load_secret(): string
{
    $file = __DIR__ . '/storefront_secret.php';
    if (!is_file($file)) {
        return '';
    }
    $secret = include $file;
    return is_string($secret) ? trim($secret) : '';
}

function sf_verify_signature(string $secret, string $timestamp, string $rawBody, string $signature): bool
{
    if (!preg_match('/^\d{9,12}$/', $timestamp)) {
        return false;
    }
    if (abs(time() - (int) $timestamp) > STOREFRONT_MAX_CLOCK_SKEW) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
    return hash_equals($expected, $signature);
}

function sf_rate_limit(string $ip): bool
{
    $dir = sys_get_temp_dir() . '/modasr_storefront_rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $file = $dir . '/' . hash('sha256', $ip) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $hits = json_decode((string) file_get_contents($file), true) ?: [];
        $hits = array_values(array_filter($hits, fn($t) => is_int($t) && $t > $now - STOREFRONT_RATE_WINDOW));
    }
    if (count($hits) >= STOREFRONT_RATE_LIMIT) {
        return false;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return true;
}

function sf_telegram_notify(string $token, string $chatId, string $text): void
{
    if ($token === '' || $chatId === '' || !ctype_digit($chatId)) {
        return;
    }
    $payload = json_encode(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML']);
    $ch = curl_init("https://api.telegram.org/bot{$token}/sendMessage");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

// ---------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sf_respond(false, 'method invalid; method must be POST', [], 405);
}

$secret = sf_load_secret();
if ($secret === '') {
    error_log('storefront_order: storefront_secret.php is missing');
    sf_respond(false, 'storefront not configured', [], 503);
}

$rawBody = (string) file_get_contents('php://input');
$timestamp = (string) ($_SERVER['HTTP_X_STOREFRONT_TIMESTAMP'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_STOREFRONT_SIGNATURE'] ?? '');

if (!sf_verify_signature($secret, $timestamp, $rawBody, $signature)) {
    sf_respond(false, 'signature invalid', [], 403);
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
if (!sf_rate_limit($ip)) {
    sf_respond(false, 'rate limit exceeded', [], 429);
}

$data = json_decode($rawBody, true);
if (!is_array($data)) {
    sf_respond(false, 'invalid json', [], 400);
}

// --- validate payload ---
$telegramId = isset($data['telegram_id']) ? trim((string) $data['telegram_id']) : '';
$orderKey = isset($data['order_key']) ? trim((string) $data['order_key']) : '';
$items = $data['items'] ?? null;
$discountCode = strtoupper(trim((string) ($data['discount_code'] ?? '')));

if (!ctype_digit($telegramId) || strlen($telegramId) > 20) {
    sf_respond(false, 'telegram_id invalid', [], 422);
}
if ($orderKey === '' || !preg_match('/^[A-Za-z0-9\-]{8,64}$/', $orderKey)) {
    sf_respond(false, 'order_key invalid', [], 422);
}
if (!is_array($items) || count($items) === 0 || count($items) > STOREFRONT_MAX_ITEMS) {
    sf_respond(false, 'items invalid', [], 422);
}

try {
    // --- user must exist in the bot ---
    $userStmt = $pdo->prepare("SELECT id, username, User_Status FROM user WHERE id = :id LIMIT 1");
    $userStmt->execute([':id' => $telegramId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        sf_respond(false, 'user not found; start the bot first', [], 404);
    }
    if (($user['User_Status'] ?? 'Active') !== 'Active') {
        sf_respond(false, 'user blocked', [], 403);
    }

    // --- idempotency: an identical retry returns the original order ---
    $itemsHash = hash('sha256', json_encode($items));
    $dupStmt = $pdo->prepare("SELECT * FROM service_other WHERE type = 'storefront' AND value LIKE :needle LIMIT 1");
    $dupStmt->execute([':needle' => '%"order_key":"' . $orderKey . '"%']);
    $existingOrder = $dupStmt->fetch(PDO::FETCH_ASSOC);
    if ($existingOrder) {
        $existingValue = json_decode((string) $existingOrder['value'], true) ?: [];
        if (empty($existingValue['items_sha256']) || !hash_equals((string) $existingValue['items_sha256'], $itemsHash)) {
            sf_respond(false, 'duplicate order_key with different items', [], 409);
        }
        sf_respond(true, 'order exists', [
            'order_no' => (string) ($existingValue['order_no'] ?? $existingOrder['id_payment']),
            'order_id' => (int) $existingOrder['id'],
            'total_toman' => (int) ($existingOrder['payable_amount'] ?? $existingOrder['price']),
            'lines' => $existingValue['lines'] ?? [],
            'idempotent_replay' => true,
        ]);
    }

    // --- resolve products, duration, coupon and tax from DB only ---
    $quote = storefrontQuote($pdo, $items, $discountCode, $telegramId);
    $lines = $quote['lines'];
    $total = $quote['total_toman'];

    // --- register pending order ---
    $orderNo = 'SF-' . strtoupper(bin2hex(random_bytes(4)));
    $value = json_encode([
        'order_key' => $orderKey,
        'order_no' => $orderNo,
        'source' => 'website',
        'items_sha256' => $itemsHash,
        'coupon' => $quote['coupon'],
        'subtotal_toman' => $quote['subtotal_toman'],
        'discount_toman' => $quote['discount_toman'],
        'tax_toman' => $quote['tax_toman'],
        'tax_bps' => $quote['tax_bps'],
        'lines' => $lines,
    ], JSON_UNESCAPED_UNICODE);

    $pdo->beginTransaction();
    storefrontConsumeCoupon($pdo, $quote, $telegramId);
    $ins = $pdo->prepare(
        "INSERT INTO service_other (id_user, username, value, type, time, price, status, output, payable_amount, id_payment)
         VALUES (:id_user, :username, :value, 'storefront', :time, :price, 'unpaid', '', :payable, :id_payment)"
    );
    $ins->execute([
        ':id_user' => $telegramId,
        ':username' => (string) ($user['username'] ?? ''),
        ':value' => $value,
        ':time' => date('Y/m/d H:i:s'),
        ':price' => (string) $quote['subtotal_toman'],
        ':payable' => (string) $total,
        ':id_payment' => $orderNo,
    ]);
    $orderId = (int) $pdo->lastInsertId();
    $pdo->commit();

    // --- notify the customer in Telegram ---
    global $PLATFORM_TOKENS;
    $botToken = isset($APIKEY) ? (string) $APIKEY : '';
    $linesFa = '';
    foreach ($lines as $l) {
        $linesFa .= '• ' . $l['name'] . ' × ' . $l['qty'] . ' — ' . number_format($l['total_toman']) . " تومان\n";
    }
    $msg = "🛒 <b>سفارش جدید شما از وب‌سایت مدثر ثبت شد</b>\n\n"
        . "شماره سفارش: <code>{$orderNo}</code>\n\n"
        . $linesFa . "\n💰 مبلغ قابل پرداخت: <b>" . number_format($total) . " تومان</b>\n\n"
        . "برای پرداخت، از منوی ربات وارد بخش «💳 کیف پول» شوید و مبلغ را شارژ کنید؛ سفارش بلافاصله بعد از پرداخت پردازش می‌شود.";
    sf_telegram_notify($botToken, $telegramId, $msg);

    sf_respond(true, 'order created', [
        'order_no' => $orderNo,
        'order_id' => $orderId,
        'total_toman' => $total,
        'subtotal_toman' => $quote['subtotal_toman'],
        'discount_toman' => $quote['discount_toman'],
        'tax_toman' => $quote['tax_toman'],
        'lines' => $lines,
    ]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sf_respond(false, $e->getMessage(), [], 422);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('storefront_order error: ' . $e->getMessage());
    sf_respond(false, 'server error', [], 500);
}
