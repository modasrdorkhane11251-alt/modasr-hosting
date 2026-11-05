<?php

/** Shared pricing, coupon, encryption, and stock-delivery primitives. */

function storefrontTaxBps(PDO $pdo): int
{
    try {
        $value = $pdo->query("SELECT storefront_tax_bps FROM setting LIMIT 1")->fetchColumn();
        return $value === false ? 500 : min(10000, max(0, (int) $value));
    } catch (Throwable $e) {
        return 500;
    }
}

function storefrontResolveItems(PDO $pdo, array $items): array
{
    $lines = [];
    foreach ($items as $item) {
        $rawCode = trim((string) ($item['code'] ?? ''));
        $qty = (int) ($item['qty'] ?? 0);
        if ($rawCode === '' || strlen($rawCode) > 120 || $qty < 1 || $qty > 50) {
            throw new InvalidArgumentException('item_invalid');
        }
        $months = 1;
        $baseCode = $rawCode;
        if (preg_match('/^(.+)~(3|6|12)$/', $rawCode, $m)) {
            $baseCode = $m[1];
            $months = (int) $m[2];
        }
        $stmt = $pdo->prepare("SELECT * FROM product WHERE code_product = ? LIMIT 1");
        $stmt->execute([$baseCode]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product || ($product['status_product'] ?? '') === 'disable') {
            throw new InvalidArgumentException('product_unavailable');
        }
        if ($months !== 1 && empty($product['multi_month'])) {
            throw new InvalidArgumentException('duration_unavailable');
        }
        $unit = ((int) $product['price_product']) * $months;
        $total = $unit * $qty;
        $lines[] = [
            'code' => $rawCode,
            'base_code' => $baseCode,
            'name' => $product['name_product'] . ($months > 1 ? ' — ' . $months . ' ماهه' : ''),
            'qty' => $qty,
            'months' => $months,
            'unit_toman' => $unit,
            'total_toman' => $total,
            'tax_included' => !isset($product['tax_included']) || (int) $product['tax_included'] === 1,
            'inventory_enabled' => !empty($product['inventory_enabled']),
            'product_id' => (int) $product['id'],
        ];
    }
    return $lines;
}

function storefrontCoupon(PDO $pdo, string $code, string $userId, array $lines): ?array
{
    if ($code === '') return null;
    if (!preg_match('/^[A-Za-z0-9]{2,40}$/', $code)) {
        throw new InvalidArgumentException('coupon_invalid');
    }
    $stmt = $pdo->prepare("SELECT * FROM DiscountSell WHERE codeDiscount = ? LIMIT 1");
    $stmt->execute([$code]);
    $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$coupon || !in_array($coupon['type'] ?? 'all', ['all', 'buy'], true)) {
        throw new InvalidArgumentException('coupon_invalid');
    }
    $now = time();
    if ((!empty($coupon['valid_from']) && $now < (int) $coupon['valid_from']) || (!empty($coupon['valid_to']) && $now > (int) $coupon['valid_to']) || (!empty($coupon['time']) && $now >= (int) $coupon['time'])) {
        throw new InvalidArgumentException('coupon_expired');
    }
    if ((int) $coupon['limitDiscount'] <= (int) $coupon['usedDiscount']) {
        throw new InvalidArgumentException('coupon_limit');
    }
    $percent = (int) $coupon['price'];
    if ($percent < 1 || $percent > 90) {
        throw new InvalidArgumentException('coupon_invalid');
    }
    $userStmt = $pdo->prepare("SELECT agent FROM user WHERE id = ? LIMIT 1");
    $userStmt->execute([$userId]);
    $agent = (string) $userStmt->fetchColumn();
    if (($coupon['agent'] ?? 'allusers') !== 'allusers' && ($coupon['agent'] ?? '') !== $agent) {
        throw new InvalidArgumentException('coupon_invalid');
    }
    $eligible = false;
    foreach ($lines as $line) {
        $productMatch = ($coupon['code_product'] ?? 'all') === 'all' || ($coupon['code_product'] ?? '') === $line['base_code'];
        if ($productMatch) {
            $eligible = true;
            break;
        }
    }
    if (!$eligible) {
        throw new InvalidArgumentException('coupon_product');
    }
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Giftcodeconsumed WHERE id_user = ? AND code = ?");
    $stmt->execute([$userId, $code]);
    $perUser = max(1, (int) ($coupon['per_user_limit'] ?? $coupon['useuser'] ?? 1));
    if ((int) $stmt->fetchColumn() >= $perUser) {
        throw new InvalidArgumentException('coupon_user_limit');
    }
    if (($coupon['usefirst'] ?? '0') === '1') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM service_other WHERE id_user = ? AND status IN ('paid_pending_delivery', 'paid')");
        $stmt->execute([$userId]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new InvalidArgumentException('coupon_first_order');
        }
    }
    return $coupon;
}

function storefrontQuote(PDO $pdo, array $items, string $couponCode, string $userId): array
{
    $lines = storefrontResolveItems($pdo, $items);
    if ($lines === []) throw new InvalidArgumentException('item_invalid');
    $coupon = storefrontCoupon($pdo, strtoupper(trim($couponCode)), $userId, $lines);
    $percent = $coupon ? (int) $coupon['price'] : 0;
    $subtotal = 0;
    $discount = 0;
    $taxable = 0;
    foreach ($lines as &$line) {
        $subtotal += $line['total_toman'];
        $eligible = $coupon && (($coupon['code_product'] ?? 'all') === 'all' || ($coupon['code_product'] ?? '') === $line['base_code']);
        $lineDiscount = $eligible ? intdiv($line['total_toman'] * $percent + 50, 100) : 0;
        $line['discount_toman'] = $lineDiscount;
        $discount += $lineDiscount;
        if (!$line['tax_included']) {
            $taxable += $line['total_toman'] - $lineDiscount;
        }
    }
    unset($line);
    $taxBps = storefrontTaxBps($pdo);
    $tax = intdiv($taxable * $taxBps + 5000, 10000);
    return [
        'lines' => $lines,
        'subtotal_toman' => $subtotal,
        'discount_toman' => $discount,
        'tax_toman' => $tax,
        'total_toman' => max(0, $subtotal - $discount + $tax),
        'coupon' => $coupon ? $coupon['codeDiscount'] : null,
        'tax_bps' => $taxBps,
    ];
}

function storefrontConsumeCoupon(PDO $pdo, array $quote, string $userId): void
{
    $code = $quote['coupon'] ?? null;
    if (!$code) return;
    // Serialize coupon consumption inside the caller's transaction.
    $lock = $pdo->prepare("SELECT id FROM DiscountSell WHERE codeDiscount = ? FOR UPDATE");
    $lock->execute([$code]);
    if (!$lock->fetchColumn()) {
        throw new RuntimeException('coupon_invalid');
    }
    $coupon = storefrontCoupon($pdo, $code, $userId, $quote['lines']);
    $stmt = $pdo->prepare("UPDATE DiscountSell SET usedDiscount = usedDiscount + 1 WHERE id = ? AND usedDiscount < limitDiscount");
    $stmt->execute([(int) $coupon['id']]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('coupon_limit');
    }
    $stmt = $pdo->prepare("INSERT INTO Giftcodeconsumed (id_user, code) VALUES (?, ?)");
    $stmt->execute([$userId, $code]);
}

function storefrontReleaseCoupon(PDO $pdo, array $order): void
{
    $value = json_decode((string) ($order['value'] ?? ''), true) ?: [];
    $code = $value['coupon'] ?? null;
    if (!$code) return;
    $stmt = $pdo->prepare("DELETE FROM Giftcodeconsumed WHERE id_user = ? AND code = ? LIMIT 1");
    $stmt->execute([(string) $order['id_user'], $code]);
    if ($stmt->rowCount() === 1) {
        $stmt = $pdo->prepare("UPDATE DiscountSell SET usedDiscount = GREATEST(0, usedDiscount - 1) WHERE codeDiscount = ?");
        $stmt->execute([$code]);
    }
}

function storefrontDeliveryText(string $output): string
{
    $data = json_decode($output, true);
    if (!is_array($data) || empty($data['lines'])) {
        return $output;
    }
    $parts = [];
    foreach ($data['lines'] as $line) {
        $parts[] = '<b>' . htmlspecialchars((string) ($line['name'] ?? 'محصول'), ENT_QUOTES, 'UTF-8') . '</b>';
        foreach (($line['items'] ?? []) as $index => $secret) {
            $parts[] = '<code>' . htmlspecialchars((string) $secret, ENT_QUOTES, 'UTF-8') . '</code>';
        }
    }
    return implode("\n\n", $parts);
}

function storefrontSecretKey(): string
{
    $file = __DIR__ . '/../api/storefront_secret.php';
    $secret = is_file($file) ? include $file : '';
    if (!is_string($secret) || trim($secret) === '') {
        throw new RuntimeException('encryption_not_configured');
    }
    return hash('sha256', trim($secret), true);
}

function storefrontEncrypt(string $plain): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', storefrontSecretKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('encryption_failed');
    return base64_encode($iv . $tag . $cipher);
}

function storefrontDecrypt(string $encoded): string
{
    $raw = base64_decode($encoded, true);
    if (!is_string($raw) || strlen($raw) < 29) throw new RuntimeException('decryption_failed');
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', storefrontSecretKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    if ($plain === false) throw new RuntimeException('decryption_failed');
    return $plain;
}

function storefrontInventoryAdd(PDO $pdo, int $productId, array $secrets): int
{
    $stmt = $pdo->prepare("INSERT INTO storefront_inventory_item (product_id, secret_ciphertext, created_at, updated_at) VALUES (?, ?, ?, ?)");
    $now = time();
    $count = 0;
    $pdo->beginTransaction();
    try {
        foreach ($secrets as $secret) {
            $secret = trim((string) $secret);
            if ($secret === '' || mb_strlen($secret, 'UTF-8') > 10000) continue;
            $stmt->execute([$productId, storefrontEncrypt($secret), $now, $now]);
            $count++;
        }
        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function storefrontFulfill(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare("SELECT * FROM service_other WHERE id = ? AND type = 'storefront' LIMIT 1");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) return ['status' => 'not_found'];
    if (($order['status'] ?? '') === 'paid') return ['status' => 'already_delivered', 'output' => $order['output']];
    if (($order['status'] ?? '') !== 'paid_pending_delivery') return ['status' => 'not_payable'];
    $value = json_decode((string) $order['value'], true) ?: [];
    $lines = $value['lines'] ?? [];
    $deliveryLines = [];
    $needsManual = false;
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT status FROM service_other WHERE id = ? FOR UPDATE");
        $stmt->execute([$orderId]);
        if ($stmt->fetchColumn() !== 'paid_pending_delivery') {
            $pdo->rollBack();
            return ['status' => 'already_processing'];
        }
        foreach ($lines as $line) {
            $qty = max(1, (int) ($line['qty'] ?? 1));
            if (empty($line['inventory_enabled'])) {
                $needsManual = true;
                continue;
            }
            $stmt = $pdo->prepare("SELECT * FROM storefront_inventory_item WHERE product_id = ? AND status = 'available' ORDER BY id LIMIT ? FOR UPDATE");
            $stmt->bindValue(1, (int) $line['product_id'], PDO::PARAM_INT);
            $stmt->bindValue(2, $qty, PDO::PARAM_INT);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($items) < $qty) {
                $needsManual = true;
                $deliveryLines = [];
                break;
            }
            $secrets = [];
            foreach ($items as $item) {
                $secrets[] = storefrontDecrypt((string) $item['secret_ciphertext']);
                $update = $pdo->prepare("UPDATE storefront_inventory_item SET status = 'delivered', order_id = ?, delivered_at = ?, updated_at = ? WHERE id = ? AND status = 'available'");
                $update->execute([$orderId, time(), time(), (int) $item['id']]);
                if ($update->rowCount() !== 1) throw new RuntimeException('inventory_race');
            }
            $deliveryLines[] = ['name' => $line['name'], 'items' => $secrets];
        }
        if ($needsManual || !$deliveryLines || count($deliveryLines) !== count(array_filter($lines, fn($l) => !empty($l['inventory_enabled'])))) {
            $pdo->rollBack();
            return ['status' => 'manual_required'];
        }
        $output = json_encode(['delivered_at' => date('c'), 'lines' => $deliveryLines], JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("UPDATE service_other SET status = 'paid', output = ? WHERE id = ? AND status = 'paid_pending_delivery'");
        $stmt->execute([$output, $orderId]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('delivery_state');
        $pdo->commit();
        return ['status' => 'delivered', 'output' => $output];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
