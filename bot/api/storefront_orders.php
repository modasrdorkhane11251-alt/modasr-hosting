<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';

$data = storefront_signed_payload();
$userId = trim((string) ($data['user_id'] ?? ''));
if ($userId === '' || !storefront_user($pdo, $userId)) storefront_json(false, 'user unavailable', [], 404);
try {
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE type = 'storefront' AND id_user = ? ORDER BY id DESC LIMIT 200");
    $stmt->execute([$userId]);
    $orders = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $value = json_decode((string) $order['value'], true) ?: [];
        $orders[] = [
            'id' => (int) $order['id'],
            'order_no' => $value['order_no'] ?? $order['id_payment'],
            'status' => $order['status'],
            'amount_toman' => (int) ($order['payable_amount'] ?? $order['price']),
            'subtotal_toman' => $value['subtotal_toman'] ?? null,
            'discount_toman' => $value['discount_toman'] ?? null,
            'tax_toman' => $value['tax_toman'] ?? null,
            'time' => $order['time'],
            'lines' => $value['lines'] ?? [],
            'delivery' => $order['status'] === 'paid' ? $order['output'] : null,
        ];
    }
    storefront_json(true, 'Successful', ['items' => $orders]);
} catch (Throwable $e) {
    error_log('storefront orders error: ' . $e->getMessage());
    storefront_json(false, 'server error', [], 500);
}
