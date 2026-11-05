<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';
require_once __DIR__ . '/../lib/storefront_payment.php';

$data = storefront_signed_payload();
$action = (string) ($data['action'] ?? '');
$userId = trim((string) ($data['user_id'] ?? ''));
$user = $userId !== '' ? storefront_user($pdo, $userId) : null;
if (!$user) storefront_json(false, 'user unavailable', [], 404);

try {
    if ($action === 'list_user') {
        $stmt = $pdo->prepare("SELECT Tracking, order_no, name_departman, text, result, time, status FROM support_message WHERE iduser = ? ORDER BY id DESC LIMIT 200");
        $stmt->execute([$userId]);
        storefront_json(true, 'Successful', ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }
    if ($action === 'create') {
        $text = trim((string) ($data['text'] ?? ''));
        $department = trim((string) ($data['department'] ?? 'فروشگاه')) ?: 'فروشگاه';
        $orderNo = trim((string) ($data['order_no'] ?? ''));
        if (mb_strlen($text, 'UTF-8') < 3 || mb_strlen($text, 'UTF-8') > 5000 || mb_strlen($department, 'UTF-8') > 100) {
            storefront_json(false, 'invalid ticket', [], 422);
        }
        if ($orderNo !== '') {
            $stmt = $pdo->prepare("SELECT id FROM service_other WHERE id_payment = ? AND id_user = ? AND type = 'storefront' LIMIT 1");
            $stmt->execute([$orderNo, $userId]);
            if (!$stmt->fetch()) storefront_json(false, 'order not found', [], 404);
        }
        $tracking = 'SUP-' . strtoupper(bin2hex(random_bytes(5)));
        $stmt = $pdo->prepare("INSERT INTO support_message (Tracking, idsupport, iduser, name_departman, text, result, time, status, order_no) VALUES (?, 'customer', ?, ?, ?, '', ?, 'Pending', ?)");
        $stmt->execute([$tracking, $userId, $department, $text, date('Y/m/d H:i:s'), $orderNo !== '' ? $orderNo : null]);
        storefront_json(true, 'ticket created', ['tracking' => $tracking]);
    }
    $tracking = trim((string) ($data['tracking'] ?? ''));
    $stmt = $pdo->prepare("SELECT * FROM support_message WHERE Tracking = ? AND iduser = ? LIMIT 1");
    $stmt->execute([$tracking, $userId]);
    $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticket) storefront_json(false, 'ticket not found', [], 404);
    if ($action === 'close') {
        $stmt = $pdo->prepare("UPDATE support_message SET status = 'close' WHERE Tracking = ? AND iduser = ?");
        $stmt->execute([$tracking, $userId]);
        storefront_json(true, 'ticket closed');
    }
    if ($action !== 'reply') storefront_json(false, 'action invalid', [], 422);
    $text = trim((string) ($data['text'] ?? ''));
    if (($ticket['status'] ?? '') === 'close' || mb_strlen($text, 'UTF-8') < 1 || mb_strlen($text, 'UTF-8') > 5000) {
        storefront_json(false, 'invalid reply', [], 422);
    }
    $stmt = $pdo->prepare("INSERT INTO support_message (Tracking, idsupport, iduser, name_departman, text, result, time, status, order_no) VALUES (?, 'customer', ?, ?, ?, '', ?, 'Customerresponse', ?)");
    $stmt->execute([$tracking, $userId, $ticket['name_departman'], $text, date('Y/m/d H:i:s'), $ticket['order_no'] ?? null]);
    storefront_json(true, 'reply added');
} catch (Throwable $e) {
    error_log('storefront ticket error: ' . $e->getMessage());
    storefront_json(false, 'server error', [], 500);
}
