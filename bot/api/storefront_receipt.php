<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';
require_once __DIR__ . '/../lib/storefront_card.php';

function storefront_receipt_photo(string $token, string $chatId, string $path, string $caption, string $keyboard): void
{
    if ($token === '' || $chatId === '') return;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendPhoto');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'photo' => new CURLFile($path),
            'caption' => $caption,
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard,
        ],
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    storefront_json(false, 'method invalid', [], 405);
}
$secret = storefront_secret_value();
if ($secret === '') storefront_json(false, 'not configured', [], 503);
$timestamp = (string) ($_SERVER['HTTP_X_STOREFRONT_TIMESTAMP'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_STOREFRONT_SIGNATURE'] ?? '');
$metaRaw = (string) ($_SERVER['HTTP_X_RECEIPT_META'] ?? '');
$bytes = (string) file_get_contents('php://input');
if (!preg_match('/^\d{9,12}$/', $timestamp) || abs(time() - (int) $timestamp) > 300) {
    storefront_json(false, 'signature invalid', [], 403);
}
$expected = hash_hmac('sha256', $timestamp . '.' . $metaRaw . '.' . $bytes, $secret);
if (!hash_equals($expected, $signature)) {
    storefront_json(false, 'signature invalid', [], 403);
}
$meta = json_decode($metaRaw, true);
if (!is_array($meta)) storefront_json(false, 'invalid metadata', [], 400);
$userId = trim((string) ($meta['user_id'] ?? ''));
$orderNo = trim((string) ($meta['order_no'] ?? ''));
$mime = trim((string) ($meta['mime'] ?? ''));
$sha256 = strtolower(trim((string) ($meta['sha256'] ?? '')));
if (!storefront_user($pdo, $userId) || !preg_match('/^SF-[A-F0-9]{8}$/', $orderNo) || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
    storefront_json(false, 'invalid metadata', [], 422);
}

try {
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE type = 'storefront' AND id_payment = ? AND id_user = ? LIMIT 1");
    $stmt->execute([$orderNo, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) storefront_json(false, 'order not found', [], 404);
    $receipt = storefrontReceiptStore($pdo, $order, $bytes, $mime, $sha256);
    $keyboard = json_encode(['inline_keyboard' => [[
        ['text' => '✅ تأیید پرداخت', 'callback_data' => 'sfapprove_' . $receipt['id']],
        ['text' => '❌ رد رسید', 'callback_data' => 'sfreject_' . $receipt['id']],
    ]]]);
    $amount = number_format((int) ($order['payable_amount'] ?? $order['price']));
    $caption = "🧾 رسید کارت‌به‌کارت وب‌سایت\n\nسفارش: <code>{$orderNo}</code>\nمبلغ: <b>{$amount} تومان</b>\nکاربر: <code>{$userId}</code>\nوضعیت: در انتظار بررسی";
    $admins = $pdo->query("SELECT id_admin FROM admin WHERE rule IN ('administrator','Seller')")->fetchAll(PDO::FETCH_COLUMN);
    global $PLATFORM_TOKENS;
    $token = (string) ($PLATFORM_TOKENS['telegram'] ?? $APIKEY ?? '');
    foreach ($admins as $adminId) {
        storefront_receipt_photo($token, (string) $adminId, $receipt['path'], $caption, $keyboard);
    }
    storefront_json(true, 'receipt submitted', [
        'receipt_id' => $receipt['id'],
        'order_no' => $orderNo,
        'status' => 'waiting',
    ]);
} catch (InvalidArgumentException $e) {
    storefront_json(false, $e->getMessage(), [], 422);
} catch (Throwable $e) {
    error_log('storefront receipt error: ' . $e->getMessage());
    storefront_json(false, 'server error', [], 500);
}
