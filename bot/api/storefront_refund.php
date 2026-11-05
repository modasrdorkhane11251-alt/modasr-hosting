<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';

$data = storefront_signed_payload();
$action = (string) ($data['action'] ?? '');
$userId = trim((string) ($data['user_id'] ?? ''));
if ($userId === '' || !storefront_user($pdo, $userId)) storefront_json(false, 'user unavailable', [], 404);

try {
    if ($action === 'list_user') {
        $stmt = $pdo->prepare("SELECT id, order_id, reason, amount_toman, status, review_note, created_at, reviewed_at FROM storefront_refund WHERE id_user = ? ORDER BY id DESC LIMIT 100");
        $stmt->execute([$userId]);
        storefront_json(true, 'Successful', ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'cancel') {
        $refundId = (int) ($data['refund_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE storefront_refund SET status = 'cancelled', reviewed_at = ? WHERE id = ? AND id_user = ? AND status = 'requested'");
        $stmt->execute([time(), $refundId, $userId]);
        if ($stmt->rowCount() !== 1) storefront_json(false, 'cannot cancel', [], 409);
        storefront_json(true, 'refund cancelled');
    }
    if ($action !== 'request') storefront_json(false, 'action invalid', [], 422);
    $orderId = (int) ($data['order_id'] ?? 0);
    $reason = trim((string) ($data['reason'] ?? ''));
    if ($orderId <= 0 || mb_strlen($reason, 'UTF-8') < 5 || mb_strlen($reason, 'UTF-8') > 2000) {
        storefront_json(false, 'invalid refund request', [], 422);
    }
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE id = ? AND type = 'storefront' AND id_user = ? AND status IN ('paid', 'paid_pending_delivery') LIMIT 1");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) storefront_json(false, 'eligible order required', [], 403);
    $amount = (int) ($order['payable_amount'] ?? $order['price'] ?? 0);
    $stmt = $pdo->prepare("INSERT INTO storefront_refund (order_id, id_user, reason, amount_toman, created_at) VALUES (?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$orderId, $userId, $reason, $amount, time()]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) storefront_json(false, 'refund already exists', [], 409);
        throw $e;
    }
    storefront_json(true, 'refund requested', ['status' => 'requested']);
} catch (Throwable $e) {
    error_log('storefront refund error: ' . $e->getMessage());
    storefront_json(false, 'server error', [], 500);
}
