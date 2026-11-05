<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';

$code = trim((string) ($_GET['code'] ?? 'telegram-premium-subscription'));
if ($code === '' || strlen($code) > 120) storefront_json(false, 'code invalid', [], 422);
try {
    $stmt = $pdo->prepare("SELECT * FROM product WHERE code_product = ? AND status_product != 'disable' LIMIT 1");
    $stmt->execute([$code]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) storefront_json(false, 'product not found', [], 404);
    $monthsList = !empty($product['multi_month']) ? [1, 3, 6, 12] : [1];
    $variants = [];
    foreach ($monthsList as $months) {
        $variants[] = [
            'months' => $months,
            'code' => $code . ($months > 1 ? '~' . $months : ''),
            'price_toman' => ((int) $product['price_product']) * $months,
            'usd_cents' => !empty($product['storefront_usd_cents']) ? ((int) $product['storefront_usd_cents']) * $months : null,
            'days' => max(1, (int) ($product['Service_time'] ?: 30)) * $months,
        ];
    }
    $stock = null;
    if (!empty($product['inventory_enabled']) && $pdo->query("SHOW TABLES LIKE 'storefront_inventory_item'")->fetch()) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM storefront_inventory_item WHERE product_id = ? AND status = 'available'");
        $stmt->execute([(int) $product['id']]);
        $stock = (int) $stmt->fetchColumn();
    }
    $reviews = ['average' => null, 'count' => 0, 'items' => []];
    if ($pdo->query("SHOW TABLES LIKE 'storefront_review'")->fetch()) {
        $stmt = $pdo->prepare("SELECT AVG(rating), COUNT(*) FROM storefront_review WHERE product_code = ? AND status = 'published'");
        $stmt->execute([$code]);
        [$average, $count] = $stmt->fetch(PDO::FETCH_NUM);
        $reviews['average'] = $average !== null ? round((float) $average, 1) : null;
        $reviews['count'] = (int) $count;
        $stmt = $pdo->prepare("SELECT rating, review_text, created_at FROM storefront_review WHERE product_code = ? AND status = 'published' ORDER BY id DESC LIMIT 10");
        $stmt->execute([$code]);
        $reviews['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $image = basename((string) ($product['storefront_image'] ?? ''));
    if ($image === '' && is_file(__DIR__ . '/../images/products/' . $code . '.jpg')) $image = $code . '.jpg';
    storefront_json(true, 'Successful', [
        'product' => [
            'code' => $code,
            'name' => $product['name_product'],
            'note' => $product['note'],
            'category' => $product['category'],
            'image_url' => $image !== '' ? 'https://' . $domainhosts . '/images/products/' . rawurlencode($image) : null,
            'multi_month' => !empty($product['multi_month']),
            'inventory_enabled' => !empty($product['inventory_enabled']),
            'stock' => $stock,
        ],
        'variants' => $variants,
        'reviews' => $reviews,
    ]);
} catch (Throwable $e) {
    error_log('storefront product error: ' . $e->getMessage());
    storefront_json(false, 'server error', [], 500);
}
