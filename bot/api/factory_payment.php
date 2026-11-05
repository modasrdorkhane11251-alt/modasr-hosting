<?php

declare(strict_types=1);

/**
 * Return null only when an order is allowed to provision a runtime.
 * Positive amounts require a server-side paid status. A zero-price order
 * is allowed only when it was explicitly created as a free order.
 */
function factoryOrderProvisionError(array $order, string $ownerId): ?string
{
    if (!isset($order['owner_id'], $order['amount_toman'], $order['status'])) {
        return 'order_invalid';
    }
    if ($ownerId === '' || !hash_equals((string) $order['owner_id'], $ownerId)) {
        return 'order_forbidden';
    }
    if (!is_numeric($order['amount_toman'])) {
        return 'order_invalid';
    }

    $amount = (int) $order['amount_toman'];
    $status = (string) $order['status'];
    if ($amount < 0) {
        return 'order_invalid';
    }
    if ($amount === 0) {
        return $status === 'free' ? null : 'payment_required';
    }

    return $status === 'paid' ? null : 'payment_required';
}

function factoryPaymentTransition(string $status, string $event): ?string
{
    return match ($event) {
        'submit_receipt' => in_array($status, ['awaiting_payment', 'payment_rejected'], true) ? 'awaiting_payment' : null,
        'approve' => $status === 'awaiting_payment' ? 'paid' : null,
        'reject' => $status === 'awaiting_payment' ? 'payment_rejected' : null,
        'cancel' => in_array($status, ['awaiting_payment', 'payment_rejected'], true) ? 'cancelled' : null,
        default => null,
    };
}

function factoryOrderStatusLabel(string $status): string
{
    return match ($status) {
        'awaiting_payment' => 'در انتظار پرداخت یا بررسی رسید',
        'payment_rejected' => 'رسید رد شده؛ نیاز به ارسال مجدد',
        'paid' => 'پرداخت تأیید شده',
        'free' => 'رایگان',
        'active' => 'ربات فعال',
        'cancelled' => 'لغو شده',
        'stopped' => 'ربات متوقف',
        'active_test' => 'آزمایشی قدیمی؛ بدون مجوز ساخت',
        'provisioning' => 'در حال آماده‌سازی',
        default => 'وضعیت نامشخص',
    };
}

function factoryOwnedOrder(PDO $pdo, int $orderId, string $ownerId): array
{
    $stmt = $pdo->prepare('SELECT * FROM factory_order WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!is_array($order)) {
        throw new RuntimeException('order_not_found');
    }
    if ($ownerId === '' || !hash_equals((string) $order['owner_id'], $ownerId)) {
        throw new RuntimeException('order_forbidden');
    }
    return $order;
}

function factoryAssertOrderPayable(PDO $pdo, string $orderId, string $ownerId): array
{
    $stmt = $pdo->prepare('SELECT * FROM factory_order WHERE id = ?');
    $stmt->execute([(int) $orderId]);
    $order = $stmt->fetch();
    if (!is_array($order)) {
        throw new RuntimeException('order_not_found');
    }

    $error = factoryOrderProvisionError($order, $ownerId);
    if ($error !== null) {
        throw new RuntimeException($error);
    }
    return $order;
}

function factoryAcquireLock(PDO $pdo, string $lockName, string $error): void
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(?, 10)');
    $stmt->execute([$lockName]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException($error);
    }
}

function factoryReleaseLock(PDO $pdo, string $lockName): void
{
    try {
        $stmt = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$lockName]);
    } catch (Throwable) {
        // The connection also releases named locks when it closes.
    }
}

/**
 * Reuse the current checkout for double clicks and create only one order
 * for the same owner/bot/price. Paid and free orders retain their original
 * server-side amount even if the advertised price changes later.
 */
function factoryCheckoutOrderReusable(array $order, int $price): bool
{
    if (!isset($order['status'], $order['amount_toman']) || !is_numeric($order['amount_toman'])) {
        return false;
    }
    $status = (string) $order['status'];
    $amount = (int) $order['amount_toman'];
    if (in_array($status, ['paid', 'active', 'stopped'], true)) {
        return true;
    }
    if ($status === 'free') {
        return $price === 0 && $amount === 0;
    }
    if (in_array($status, ['awaiting_payment', 'payment_rejected'], true)) {
        return $amount === $price;
    }
    return false;
}

function factoryCheckoutOrder(PDO $pdo, string $ownerId, string $botName, int $price, string $planCode = 'vpn'): array
{
    if ($ownerId === '' || $botName === '' || $price < 0 || $price > 1000000000) {
        throw new RuntimeException('order_invalid');
    }
    if ($planCode !== 'vpn') {
        factoryPlan($planCode);
    }

    $lockName = substr('factory_checkout_' . hash('sha256', $ownerId . '|' . $botName), 0, 60);
    factoryAcquireLock($pdo, $lockName, 'checkout_lock_failed');
    try {
        $stmt = $pdo->prepare('SELECT * FROM factory_order WHERE owner_id = ? AND bot_name = ? AND plan_code = ? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$ownerId, $botName, $planCode]);
        $order = $stmt->fetch();
        if (is_array($order) && factoryCheckoutOrderReusable($order, $price)) {
            return $order;
        }

        $status = $price === 0 ? 'free' : 'awaiting_payment';
        $insert = $pdo->prepare('INSERT INTO factory_order (owner_id, bot_name, plan_code, amount_toman, status) VALUES (?, ?, ?, ?, ?)');
        $insert->execute([$ownerId, $botName, $planCode, $price, $status]);

        return [
            'id' => (int) $pdo->lastInsertId(),
            'owner_id' => $ownerId,
            'bot_name' => $botName,
            'plan_code' => $planCode,
            'amount_toman' => $price,
            'status' => $status,
        ];
    } finally {
        factoryReleaseLock($pdo, $lockName);
    }
}

/**
 * Atomic manual payment confirmation for the owner after verifying an
 * off-platform payment. Repeating an already-paid claim is idempotent.
 */
function factoryClaimOrderPaid(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT * FROM factory_order WHERE id = ?');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!is_array($order)) {
        throw new RuntimeException('order_not_found');
    }
    if ((string) $order['status'] === 'paid') {
        $order['_payment_claimed'] = false;
        return $order;
    }
    if (factoryPaymentTransition((string) $order['status'], 'approve') !== 'paid' || (int) $order['amount_toman'] <= 0) {
        throw new RuntimeException('payment_claim_invalid');
    }

    $update = $pdo->prepare('UPDATE factory_order SET status = "paid" WHERE id = ? AND status = "awaiting_payment" AND amount_toman > 0');
    $update->execute([$orderId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('payment_claim_conflict');
    }

    $order['status'] = 'paid';
    $order['_payment_claimed'] = true;
    return $order;
}
