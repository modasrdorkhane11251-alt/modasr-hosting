<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';

$data = storefront_signed_payload();
$action = (string) ($data['action'] ?? '');
$userId = trim((string) ($data['user_id'] ?? ''));
if ($userId === '' || !storefront_user($pdo, $userId)) storefront_json(false, 'user unavailable', [], 404);

try {
    if ($action === 'list_user') {
        $stmt = $pdo->prepare("SELECT order_id, product_code, rating, review_text, status, created_at FROM storefront_review WHERE id_user = ? ORDER BY id DESC LIMIT 100");
        $stmt->execute([$userId]);
        storefront_json(true, 'Successful', ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action !== 'submit') storefront_json(false, 'action invalid', [], 422);
    $orderId = (int) ($data['order_id'] ?? 0);
    $productCode = trim((string) ($data['product_code'] ?? ''));
    $rating = (int) ($data['rating'] ?? 0);
    $text = trim((string) ($data['review_text'] ?? ''));
    if ($orderId <= 0 || $productCode === '' || $rating < 1 || $rating > 5 || mb_strlen($text, 'UTF-8') < 3 || mb_strlen($text, 'UTF-8') > 2000) {
        storefront_json(false, 'invalid review', [], 422);
    }
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE id = ? AND type = 'storefront' AND id_user = ? AND status = 'paid' LIMIT 1");
    $stmt->execute([$orderId, $userId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) storefront_json(false, 'paid order required', [], 403);
    $value = json_decode((string) $order['value'], true) ?: [];
    $purchased = false;
    foreach (($value['lines'] ?? []) as $line) {
        if (($line['base_code'] ?? $line['code'] ?? '') === $productCode) { $purchased = true; break; }
    }
    if (!$purchased) storefront_json(false, 'product not in order', [], 403);
    $stmt = $pdo->prepare("INSERT INTO storefront_review (order_id, product_code, id_user, rating, review_text, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    try {
        $stmt->execute([$orderId, $productCode, $userId, $rating, $text, time()]);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) storefront_json(false, 'review already exists', [], 409);
        throw $e;
    }
    storefront_json(true, 'review submitted', ['status' => 'pending']);
} catch (Throwable $e) {
    error_log('storefront review error: ' . $e->getMessage());
    storefront_json(false, 'server error', [], 500);
}
