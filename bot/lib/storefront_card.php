<?php

require_once __DIR__ . '/storefront_core.php';
require_once __DIR__ . '/storefront_payment.php';

function storefrontReceiptDir(): string
{
    $dir = getenv('MODASR_RECEIPT_DIR') ?: '/var/www/modasr-private/receipts';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        throw new RuntimeException('receipt_storage');
    }
    return $dir;
}

function storefrontReceiptMime(string $bytes, string $declared): string
{
    $jpeg = str_starts_with($bytes, "\xFF\xD8\xFF");
    $png = str_starts_with($bytes, "\x89PNG\x0D\x0A\x1A\x0A");
    $webp = str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP';
    if ($jpeg) return 'image/jpeg';
    if ($png) return 'image/png';
    if ($webp) return 'image/webp';
    throw new InvalidArgumentException('receipt_image_type');
}

function storefrontReceiptStore(PDO $pdo, array $order, string $bytes, string $declaredMime, string $sha256): array
{
    if (strlen($bytes) < 100 || strlen($bytes) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('receipt_image_size');
    }
    if (!hash_equals(strtolower($sha256), hash('sha256', $bytes))) {
        throw new InvalidArgumentException('receipt_hash');
    }
    $mime = storefrontReceiptMime($bytes, $declaredMime);
    if ($declaredMime !== '' && $declaredMime !== $mime) {
        throw new InvalidArgumentException('receipt_mime_mismatch');
    }
    if (($order['type'] ?? '') !== 'storefront' || ($order['status'] ?? '') !== 'unpaid') {
        throw new InvalidArgumentException('receipt_order_state');
    }
    $orderNo = sf_order_no($order);
    if ($orderNo === '') throw new InvalidArgumentException('receipt_order');
    $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime];
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $path = storefrontReceiptDir() . '/' . $filename;
    if (file_put_contents($path, $bytes, LOCK_EX) === false) {
        throw new RuntimeException('receipt_storage');
    }
    @chmod($path, 0640);
    $now = time();
    $stmt = $pdo->prepare("INSERT INTO storefront_receipt (order_id, order_no, id_user, file_path, mime, sha256, status, created_at) VALUES (?, ?, ?, ?, ?, ?, 'waiting', ?)");
    $stmt->execute([(int) $order['id'], $orderNo, (string) $order['id_user'], $path, $mime, $sha256, $now]);
    $receiptId = (int) $pdo->lastInsertId();

    $stmt = $pdo->prepare("SELECT id FROM Payment_report WHERE id_order = ? LIMIT 1");
    $stmt->execute([$orderNo]);
    if (!$stmt->fetch()) {
        $stmt = $pdo->prepare("INSERT INTO Payment_report (id_user, id_order, time, price, payment_Status, Payment_Method, dec_not_confirmed) VALUES (?, ?, ?, ?, 'waiting', 'cart to cart', ?)");
        $stmt->execute([(string) $order['id_user'], $orderNo, date('Y/m/d H:i:s'), (string) ($order['payable_amount'] ?? $order['price']), 'receipt:' . $receiptId]);
    } else {
        $stmt = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'waiting', Payment_Method = 'cart to cart', dec_not_confirmed = ? WHERE id_order = ? AND payment_Status <> 'paid'");
        $stmt->execute(['receipt:' . $receiptId, $orderNo]);
    }
    return ['id' => $receiptId, 'order_no' => $orderNo, 'mime' => $mime, 'path' => $path];
}

function storefrontReviewReceipt(PDO $pdo, int $receiptId, string $reviewer, string $decision, string $note = ''): array
{
    $stmt = $pdo->prepare("SELECT * FROM storefront_receipt WHERE id = ? LIMIT 1");
    $stmt->execute([$receiptId]);
    $receipt = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$receipt) throw new RuntimeException('receipt_not_found');
    if (($receipt['status'] ?? '') !== 'waiting') {
        return ['status' => 'already_reviewed', 'order_no' => $receipt['order_no']];
    }
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE id = ? AND type = 'storefront' LIMIT 1");
    $stmt->execute([(int) $receipt['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('receipt_order');
    $orderNo = (string) $receipt['order_no'];
    $now = time();

    if ($decision === 'reject') {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE storefront_receipt SET status = 'rejected', reviewer = ?, review_note = ?, reviewed_at = ? WHERE id = ? AND status = 'waiting'");
            $stmt->execute([$reviewer, $note !== '' ? $note : null, $now, $receiptId]);
            $stmt = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'reject', dec_not_confirmed = ? WHERE id_order = ? AND payment_Status <> 'paid'");
            $stmt->execute([$note !== '' ? $note : 'rejected_by_admin', $orderNo]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['status' => 'rejected', 'order_no' => $orderNo, 'order' => $order, 'receipt' => $receipt];
    }
    if ($decision !== 'approve') {
        throw new InvalidArgumentException('review_decision');
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE storefront_receipt SET status = 'approved', reviewer = ?, review_note = ?, reviewed_at = ? WHERE id = ? AND status = 'waiting'");
        $stmt->execute([$reviewer, $note !== '' ? $note : null, $now, $receiptId]);
        $stmt = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = ? AND payment_Status <> 'paid'");
        $stmt->execute([$orderNo]);
        $stmt = $pdo->prepare("UPDATE service_other SET status = 'paid_pending_delivery' WHERE id = ? AND status = 'unpaid'");
        $stmt->execute([(int) $order['id']]);
        $orderClaim = $stmt->rowCount() === 1;
        if (!$orderClaim) {
            $current = $pdo->prepare("SELECT status FROM service_other WHERE id = ?");
            $current->execute([(int) $order['id']]);
            $currentStatus = (string) $current->fetchColumn();
            if (!in_array($currentStatus, ['paid_pending_delivery', 'paid'], true)) {
                throw new RuntimeException('receipt_order_state');
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    $fulfillment = storefrontFulfill($pdo, (int) $order['id']);
    return ['status' => 'approved', 'fulfillment' => $fulfillment['status'] ?? 'manual_required', 'order_no' => $orderNo, 'order' => $order, 'receipt' => $receipt, 'output' => $fulfillment['output'] ?? null];
}

function storefrontReceiptUserNotice(array $result): string
{
    $orderNo = (string) ($result['order_no'] ?? '');
    if (($result['status'] ?? '') === 'rejected') {
        return "❌ رسید پرداخت سفارش {$orderNo} رد شد. لطفاً دلیل را با پشتیبانی بررسی کنید و در صورت نیاز رسید واضح‌تری ارسال کنید.";
    }
    if (($result['fulfillment'] ?? '') === 'delivered') {
        return "✅ پرداخت سفارش {$orderNo} توسط ادمین تأیید شد و محصول برای شما ارسال شد.";
    }
    return "✅ رسید پرداخت سفارش {$orderNo} توسط ادمین تأیید شد و سفارش به صف تحویل رفت.";
}
