<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';
require_once __DIR__ . '/../lib/storefront_payment.php';

$data = storefront_signed_payload();
$userId = trim((string) ($data['user_id'] ?? ''));
$orderNo = trim((string) ($data['order_no'] ?? ''));
if ($userId === '' || !storefront_user($pdo, $userId) || !preg_match('/^SF-[A-F0-9]{8}$/', $orderNo)) {
    storefront_json(false, 'invalid request', [], 422);
}
try {
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE type = 'storefront' AND id_payment = ? AND id_user = ? LIMIT 1");
    $stmt->execute([$orderNo, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) storefront_json(false, 'order not found', [], 404);
    $card = $pdo->query("SELECT cardnumber, namecard FROM card_number ORDER BY cardnumber LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$card) storefront_json(false, 'card not configured', [], 503);
    storefront_json(true, 'Successful', [
        'order_no' => $orderNo,
        'amount_toman' => (int) ($order['payable_amount'] ?? $order['price']),
        'status' => $order['status'],
        'card_number' => $card['cardnumber'],
        'card_name' => $card['namecard'],
    ]);
} catch (Throwable $e) {
    error_log('storefront card info error: ' . $e->getMessage());
    storefront_json(false, 'server error', [], 500);
}
