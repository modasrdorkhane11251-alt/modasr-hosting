<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/storefront_payment.php';

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Asia/Tehran');

function sfp_respond(bool $ok, string $msg, array $obj = [], int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['status' => $ok, 'msg' => $msg, 'obj' => $obj], JSON_UNESCAPED_UNICODE);
    exit;
}

function sfp_secret(): string
{
    $file = __DIR__ . '/storefront_secret.php';
    if (!is_file($file)) return '';
    $secret = include $file;
    return is_string($secret) ? trim($secret) : '';
}

function sfp_signed(string $secret, string $timestamp, string $raw, string $signature): bool
{
    if (!preg_match('/^\d{9,12}$/', $timestamp) || abs(time() - (int) $timestamp) > 300) return false;
    return hash_equals(hash_hmac('sha256', $timestamp . '.' . $raw, $secret), $signature);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sfp_respond(false, 'method invalid', [], 405);
}
$secret = sfp_secret();
if ($secret === '') sfp_respond(false, 'not configured', [], 503);
$raw = (string) file_get_contents('php://input');
$timestamp = (string) ($_SERVER['HTTP_X_STOREFRONT_TIMESTAMP'] ?? '');
$signature = (string) ($_SERVER['HTTP_X_STOREFRONT_SIGNATURE'] ?? '');
if (!sfp_signed($secret, $timestamp, $raw, $signature)) {
    sfp_respond(false, 'signature invalid', [], 403);
}
$data = json_decode($raw, true);
$orderNo = trim((string) ($data['order_no'] ?? ''));
if (!preg_match('/^SF-[A-F0-9]{8}$/', $orderNo)) {
    sfp_respond(false, 'order_no invalid', [], 422);
}
if (!$pdo->query("SHOW TABLES LIKE 'storefront_payment'")->fetch()) {
    sfp_respond(false, 'migration required', [], 503);
}
$pin = sf_aqaye_merchant($pdo);
if ($pin === '') sfp_respond(false, 'payment not configured', [], 503);

try {
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE type = 'storefront' AND id_payment = ? LIMIT 1");
    $stmt->execute([$orderNo]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) sfp_respond(false, 'order not found', [], 404);
    if (($order['status'] ?? '') !== 'unpaid') {
        sfp_respond(false, 'order is not payable', ['order_no' => $orderNo, 'order_status' => $order['status']], 409);
    }
    $amount = (int) ($order['payable_amount'] ?? $order['price'] ?? 0);
    if ($amount < 1000) sfp_respond(false, 'order amount invalid', [], 422);

    $stmt = $pdo->prepare("SELECT * FROM storefront_payment WHERE order_id = ? AND provider = 'aqayepardakht' LIMIT 1");
    $stmt->execute([(int) $order['id']]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($payment && $payment['status'] === 'paid') {
        sfp_respond(false, 'already paid', ['order_no' => $orderNo], 409);
    }
    if ($payment && $payment['status'] === 'pending' && !empty($payment['provider_transid'])) {
        sfp_respond(true, 'payment resumed', [
            'order_no' => $orderNo,
            'amount_toman' => $amount,
            'gateway_url' => 'https://panel.aqayepardakht.ir/startpay/' . rawurlencode((string) $payment['provider_transid']),
        ]);
    }
    if ($payment && in_array($payment['status'], ['creating', 'unknown'], true)) {
        sfp_respond(false, 'payment creation unknown', ['order_no' => $orderNo], 409);
    }

    $now = time();
    if ($payment) {
        $stmt = $pdo->prepare("UPDATE storefront_payment SET status = 'creating', error = NULL, updated_at = ? WHERE id = ?");
        $stmt->execute([$now, (int) $payment['id']]);
        $paymentId = (int) $payment['id'];
    } else {
        $stmt = $pdo->prepare("INSERT INTO storefront_payment (order_id, order_no, provider, provider_order_id, amount_toman, status, created_at, updated_at) VALUES (?, ?, 'aqayepardakht', ?, ?, 'creating', ?, ?)");
        $stmt->execute([(int) $order['id'], $orderNo, $orderNo, $amount, $now, $now]);
        $paymentId = (int) $pdo->lastInsertId();
    }

    $callback = 'https://' . $domainhosts . '/payment/storefront_aqayepardakht.php';
    $created = sf_aqaye_create($pin, $amount, $orderNo, $callback);
    if (($created['status'] ?? '') === 'pending') {
        $transid = (string) $created['transid'];
        $stmt = $pdo->prepare("UPDATE storefront_payment SET status = 'pending', provider_transid = ?, error = NULL, updated_at = ? WHERE id = ?");
        $stmt->execute([$transid, time(), $paymentId]);
        sfp_respond(true, 'payment created', [
            'order_no' => $orderNo,
            'amount_toman' => $amount,
            'gateway_url' => 'https://panel.aqayepardakht.ir/startpay/' . rawurlencode($transid),
        ]);
    }
    $status = ($created['status'] ?? '') === 'failed' ? 'failed' : 'unknown';
    $stmt = $pdo->prepare("UPDATE storefront_payment SET status = ?, error = ?, updated_at = ? WHERE id = ?");
    $stmt->execute([$status, (string) ($created['error'] ?? 'provider_error'), time(), $paymentId]);
    sfp_respond(false, $status === 'failed' ? 'payment creation failed' : 'payment creation unknown', ['order_no' => $orderNo], 502);
} catch (Throwable $e) {
    error_log('storefront payment create error: ' . $e->getMessage());
    sfp_respond(false, 'server error', [], 500);
}
