<?php

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

function sfos_respond(bool $ok, string $msg, array $obj = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['status' => $ok, 'msg' => $msg, 'obj' => $obj], JSON_UNESCAPED_UNICODE);
    exit;
}
function sfos_secret(): string
{
    $file = __DIR__ . '/storefront_secret.php';
    if (!is_file($file)) return '';
    $secret = include $file;
    return is_string($secret) ? trim($secret) : '';
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') sfos_respond(false, 'method invalid', [], 405);
$raw = (string) file_get_contents('php://input');
$timestamp = (string) ($_SERVER['HTTP_X_STOREFRONT_TIMESTAMP'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_STOREFRONT_SIGNATURE'] ?? '');
$secret = sfos_secret();
if ($secret === '') sfos_respond(false, 'not configured', [], 503);
if (!preg_match('/^\d{9,12}$/', $timestamp) || abs(time() - (int) $timestamp) > 300 || !hash_equals(hash_hmac('sha256', $timestamp . '.' . $raw, $secret), $signature)) {
    sfos_respond(false, 'signature invalid', [], 403);
}
$data = json_decode($raw, true);
$orderNo = trim((string) ($data['order_no'] ?? ''));
$telegramId = trim((string) ($data['telegram_id'] ?? ''));
if (!preg_match('/^SF-[A-F0-9]{8}$/', $orderNo) || !ctype_digit($telegramId) || strlen($telegramId) > 20) {
    sfos_respond(false, 'invalid payload', [], 422);
}
try {
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE type = 'storefront' AND id_payment = ? AND id_user = ? LIMIT 1");
    $stmt->execute([$orderNo, $telegramId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) sfos_respond(false, 'order not found', [], 404);
    $value = json_decode((string) $order['value'], true) ?: [];
    $paymentStatus = null;
    if ($pdo->query("SHOW TABLES LIKE 'storefront_payment'")->fetch()) {
        $stmt = $pdo->prepare("SELECT status FROM storefront_payment WHERE order_id = ? AND provider = 'aqayepardakht' LIMIT 1");
        $stmt->execute([(int) $order['id']]);
        $paymentStatus = $stmt->fetchColumn() ?: null;
    }
    sfos_respond(true, 'Successful', [
        'order_no' => $orderNo,
        'status' => $order['status'],
        'payment_status' => $paymentStatus,
        'amount_toman' => (int) ($order['payable_amount'] ?? $order['price']),
        'lines' => $value['lines'] ?? [],
        'delivery' => $order['status'] === 'paid' ? (string) $order['output'] : null,
    ]);
} catch (Throwable $e) {
    error_log('storefront order status error: ' . $e->getMessage());
    sfos_respond(false, 'server error', [], 500);
}
