<?php

/** Reconcile provider payments and abandon only definitively unpaid orders. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/storefront_payment.php';
require_once __DIR__ . '/../lib/storefront_core.php';

$pin = sf_aqaye_merchant($pdo);
$now = time();
$stats = ['verified_paid' => 0, 'failed' => 0, 'unknown' => 0, 'expired' => 0];
if ($pin === '') {
    echo json_encode($stats) . "\n";
    exit(0);
}

$stmt = $pdo->query("SELECT p.*, o.id_user, o.price, o.payable_amount, o.type FROM storefront_payment p JOIN service_other o ON o.id = p.order_id WHERE p.status IN ('pending', 'unknown') AND p.updated_at < " . ($now - 900) . " ORDER BY p.id LIMIT 50");
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($payments as $payment) {
    $orderStmt = $pdo->prepare("SELECT * FROM service_other WHERE id = ? LIMIT 1");
    $orderStmt->execute([(int) $payment['order_id']]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || empty($payment['provider_transid'])) continue;
    $verified = sf_aqaye_verify($pin, (int) $payment['amount_toman'], (string) $payment['provider_transid']);
    if ($verified['paid']) {
        $result = storefrontClaimPaid($pdo, $order, $payment, (string) $payment['provider_transid']);
        if (in_array($result, ['claimed', 'already_paid'], true)) {
            $stats['verified_paid']++;
            try {
                storefrontFulfill($pdo, (int) $order['id']);
            } catch (Throwable $e) {
                error_log('storefront reconcile delivery error: ' . $e->getMessage());
            }
        }
        continue;
    }
    if ($verified['unknown']) {
        $stats['unknown']++;
        $stmt = $pdo->prepare("UPDATE storefront_payment SET status = 'unknown', error = 'verify_transport', updated_at = ? WHERE id = ? AND status <> 'paid'");
        $stmt->execute([$now, (int) $payment['id']]);
        continue;
    }
    if ($now - (int) $payment['updated_at'] >= 86400) {
        $stats['failed']++;
        $stmt = $pdo->prepare("UPDATE storefront_payment SET status = 'failed', error = 'verify_rejected', updated_at = ? WHERE id = ? AND status <> 'paid'");
        $stmt->execute([$now, (int) $payment['id']]);
    } else {
        $stats['unknown']++;
    }
}

// Expire only old unpaid orders with a definitively failed or absent payment.
$stmt = $pdo->query("SELECT * FROM service_other WHERE type = 'storefront' AND status = 'unpaid' ORDER BY id ASC LIMIT 200");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
    $created = strtotime((string) $order['time']);
    if (!$created || $now - $created < 172800) continue;
    $paymentStmt = $pdo->prepare("SELECT * FROM storefront_payment WHERE order_id = ? AND provider = 'aqayepardakht' LIMIT 1");
    $paymentStmt->execute([(int) $order['id']]);
    $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC);
    if ($payment && !in_array($payment['status'], ['failed', 'cancelled'], true)) continue;
    $pdo->beginTransaction();
    try {
        $update = $pdo->prepare("UPDATE service_other SET status = 'expired' WHERE id = ? AND status = 'unpaid'");
        $update->execute([(int) $order['id']]);
        if ($update->rowCount() === 1) {
            storefrontReleaseCoupon($pdo, $order);
            $stats['expired']++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

echo json_encode($stats) . "\n";
