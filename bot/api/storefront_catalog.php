<?php

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=10');
header('X-Content-Type-Options: nosniff');
date_default_timezone_set('Asia/Tehran');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => false, 'msg' => 'method invalid; method must be GET', 'obj' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $columnRows = $pdo->query("SHOW COLUMNS FROM product")->fetchAll(PDO::FETCH_ASSOC);
    $columns = array_fill_keys(array_column($columnRows, 'Field'), true);
    $hasUsd = isset($columns['storefront_usd_cents']);
    $hasImage = isset($columns['storefront_image']);

    $categories = $pdo
        ->query("SELECT id, remark, display_order FROM category ORDER BY display_order, id")
        ->fetchAll(PDO::FETCH_ASSOC);

    $usdSelect = $hasUsd ? 'storefront_usd_cents' : 'NULL AS storefront_usd_cents';
    $imageSelect = $hasImage ? 'storefront_image' : 'NULL AS storefront_image';
    $stmt = $pdo->query(
        "SELECT id, code_product, name_product, price_product, Volume_constraint, Service_time, note, category, status_product, display_order, $usdSelect, $imageSelect
         FROM product
         WHERE status_product != 'disable'
         ORDER BY display_order, id
         LIMIT 1000"
    );
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($products as &$product) {
        $filename = basename((string) ($product['storefront_image'] ?? ''));
        if ($filename === '') {
            $candidate = basename((string) $product['code_product']) . '.jpg';
            if (is_file(__DIR__ . '/../images/products/' . $candidate)) {
                $filename = $candidate;
            }
        }
        $product['image_url'] = $filename !== '' && is_file(__DIR__ . '/../images/products/' . $filename)
            ? 'https://' . $domainhosts . '/images/products/' . rawurlencode($filename)
            : null;
        unset($product['storefront_image']);
    }
    unset($product);

    $total = (int) $pdo->query("SELECT COUNT(*) FROM product WHERE status_product != 'disable'")->fetchColumn();
    echo json_encode([
        'status' => true,
        'msg' => 'Successful',
        'obj' => [
            'products' => $products,
            'category' => $categories,
            'pagination' => ['total_product' => $total, 'total_pages' => 1, 'current_page' => 1, 'per_page' => 1000],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Exception $e) {
    error_log('Storefront catalog error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => false, 'msg' => 'catalog unavailable', 'obj' => []], JSON_UNESCAPED_UNICODE);
}
