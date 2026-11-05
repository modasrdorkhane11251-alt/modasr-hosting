<?php

/** AqayePardakht transport and pure storefront-payment invariants. */

function sf_aqaye_merchant(PDO $pdo): string
{
    $stmt = $pdo->prepare("SELECT ValuePay FROM PaySetting WHERE NamePay = 'merchant_id_aqayepardakht' LIMIT 1");
    $stmt->execute();
    return trim((string) $stmt->fetchColumn());
}

function sf_aqaye_call(string $method, string $pin, array $payload, ?callable $transport = null): array
{
    $url = 'https://panel.aqayepardakht.ir/api/v2/' . ($method === 'create' ? 'create' : 'verify');
    $payload = ['pin' => $pin] + $payload;
    if ($transport !== null) {
        return $transport($url, $payload);
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) {
        return ['transport_ok' => false, 'http' => $http, 'decoded' => null];
    }
    $decoded = json_decode((string) $raw, true);
    return ['transport_ok' => true, 'http' => $http, 'decoded' => is_array($decoded) ? $decoded : null];
}

function sf_aqaye_create(string $pin, int $amount, string $orderNo, string $callbackUrl, ?callable $transport = null): array
{
    $result = sf_aqaye_call('create', $pin, ['amount' => $amount, 'callback' => $callbackUrl, 'invoice_id' => $orderNo], $transport);
    if (!($result['transport_ok'] ?? false)) {
        return ['status' => 'unknown', 'error' => 'provider_transport'];
    }
    $decoded = $result['decoded'] ?? null;
    if (!is_array($decoded)) {
        return ['status' => 'unknown', 'error' => 'provider_invalid_response'];
    }
    if (($decoded['status'] ?? '') === 'success' && !empty($decoded['transid'])) {
        return ['status' => 'pending', 'transid' => (string) $decoded['transid'], 'error' => null];
    }
    return ['status' => 'failed', 'error' => 'provider_rejected'];
}

function sf_aqaye_verify(string $pin, int $amount, string $transid, ?callable $transport = null): array
{
    $result = sf_aqaye_call('verify', $pin, ['amount' => $amount, 'transid' => $transid], $transport);
    if (!($result['transport_ok'] ?? false) || !is_array($result['decoded'] ?? null)) {
        return ['paid' => false, 'unknown' => true];
    }
    return ['paid' => (string) ($result['decoded']['code'] ?? '') === '1', 'unknown' => false];
}

function sf_claim_eligibility(?array $order, ?array $payment, string $transid): string
{
    if (!$order || ($order['type'] ?? '') !== 'storefront') {
        return 'invalid_order';
    }
    if (!$payment) {
        return 'invalid_payment';
    }
    $orderAmount = (int) ($order['payable_amount'] ?? $order['price'] ?? 0);
    if ($orderAmount <= 0 || $orderAmount !== (int) ($payment['amount_toman'] ?? 0)) {
        return 'amount_mismatch';
    }
    if (!preg_match('/^[A-Za-z0-9_\-]{3,128}$/', $transid)) {
        return 'invalid_transaction';
    }
    if (!empty($payment['provider_transid']) && !hash_equals((string) $payment['provider_transid'], $transid)) {
        return 'transaction_mismatch';
    }
    $orderPaid = in_array($order['status'] ?? '', ['paid_pending_delivery', 'paid'], true);
    $paymentPaid = ($payment['status'] ?? '') === 'paid';
    if ($orderPaid && $paymentPaid) {
        return 'already_paid';
    }
    if (in_array($order['status'] ?? '', ['unpaid', 'paid_pending_delivery', 'paid'], true)) {
        return 'claim';
    }
    return 'invalid_state';
}

function sf_telegram_send(string $token, string $chatId, string $text, ?int $topicId = null): void
{
    if ($token === '' || $chatId === '') {
        return;
    }
    $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($topicId !== null && $topicId > 0) {
        $payload['message_thread_id'] = $topicId;
    }
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

function storefrontClaimPaid(PDO $pdo, array $order, array $payment, string $transid): string
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM service_other WHERE id = ? FOR UPDATE");
        $stmt->execute([(int) $order['id']]);
        $lockedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare("SELECT * FROM storefront_payment WHERE id = ? FOR UPDATE");
        $stmt->execute([(int) $payment['id']]);
        $lockedPayment = $stmt->fetch(PDO::FETCH_ASSOC);
        $eligibility = sf_claim_eligibility($lockedOrder, $lockedPayment, $transid);
        if ($eligibility === 'already_paid') {
            $pdo->commit();
            return 'already_paid';
        }
        if ($eligibility !== 'claim') {
            $pdo->rollBack();
            return $eligibility;
        }
        $stmt = $pdo->prepare("UPDATE storefront_payment SET status = 'paid', provider_transid = ?, paid_at = ?, updated_at = ?, error = NULL WHERE id = ? AND status <> 'paid'");
        $stmt->execute([$transid, time(), time(), (int) $payment['id']]);
        $paymentClaim = $stmt->rowCount() === 1;
        $stmt = $pdo->prepare("UPDATE service_other SET status = 'paid_pending_delivery' WHERE id = ? AND status = 'unpaid'");
        $stmt->execute([(int) $order['id']]);
        $orderClaim = $stmt->rowCount() === 1;
        $pdo->commit();
        return ($paymentClaim || $orderClaim) ? 'claimed' : 'already_paid';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function sf_order_no(array $order): string
{
    $value = json_decode((string) ($order['value'] ?? ''), true);
    return (string) ($value['order_no'] ?? $order['id_payment'] ?? '');
}
