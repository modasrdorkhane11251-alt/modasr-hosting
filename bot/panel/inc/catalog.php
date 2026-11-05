<?php

/** Shared catalog helpers for the authenticated admin panel. */

function catalogColumns(PDO $pdo): array
{
    static $columns = null;
    if ($columns !== null) {
        return $columns;
    }
    $columns = ['storefront_usd_cents' => false, 'storefront_image' => false, 'multi_month' => false, 'inventory_enabled' => false, 'tax_included' => false];
    $rows = $pdo->query("SHOW COLUMNS FROM product")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $field = (string) $row['Field'];
        if (array_key_exists($field, $columns)) {
            $columns[$field] = true;
        }
    }
    return $columns;
}

function catalogToman(string $value): int
{
    $value = trim($value);
    if (!preg_match('/^\d{1,12}$/', $value)) {
        throw new InvalidArgumentException('invalid_toman');
    }
    return (int) $value;
}

function catalogUsdCents(string $value): int
{
    $value = trim($value);
    if (!preg_match('/^(\d{1,9})(?:\.(\d{1,2}))?$/', $value, $m)) {
        throw new InvalidArgumentException('invalid_usd');
    }
    $cents = ((int) $m[1] * 100) + (int) str_pad($m[2] ?? '', 2, '0');
    if ($cents <= 0) {
        throw new InvalidArgumentException('invalid_usd');
    }
    return $cents;
}

/**
 * Return [toman, usd_cents|null] using exact integer arithmetic.
 * Dynamic website prices are stored as cents; the browser converts those
 * cents with the live USDT rate. Bot payments always use the Toman column.
 */
function catalogResolvePricing(string $mode, string $tomanInput, string $usdInput, ?int $usdtRate): array
{
    if ($mode === 'fixed') {
        return [catalogToman($tomanInput), null];
    }
    if ($mode !== 'dynamic') {
        throw new InvalidArgumentException('invalid_pricing_mode');
    }
    if ($usdtRate === null || $usdtRate <= 0) {
        throw new RuntimeException('rate_unavailable');
    }
    $cents = catalogUsdCents($usdInput);
    $toman = intdiv($cents * $usdtRate + 50, 100);
    if ($toman <= 0) {
        throw new InvalidArgumentException('invalid_toman');
    }
    return [$toman, $cents];
}

function catalogLiveUsdtRate(): ?int
{
    static $rate = null;
    if ($rate !== null) {
        return $rate > 0 ? $rate : null;
    }
    $rate = 0;
    $cacheFile = sys_get_temp_dir() . '/modasr_panel_usdt_rate.json';
    $cache = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;
    if (is_array($cache) && time() - (int) ($cache['time'] ?? 0) < 30 && (int) ($cache['rate'] ?? 0) > 0) {
        $rate = (int) $cache['rate'];
        return $rate;
    }
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init('https://api.wallex.ir/v1/markets');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 3]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $json = json_decode((string) $raw, true);
    $value = $json['result']['symbols']['USDTTMN']['stats']['lastPrice'] ?? null;
    if (is_numeric($value) && (int) $value > 0) {
        $rate = (int) $value;
        @file_put_contents($cacheFile, json_encode(['time' => time(), 'rate' => $rate]), LOCK_EX);
        return $rate;
    }
    // A short-lived stale value is safer than silently saving a wrong price.
    if (is_array($cache) && time() - (int) ($cache['time'] ?? 0) < 300 && (int) ($cache['rate'] ?? 0) > 0) {
        $rate = (int) $cache['rate'];
        return $rate;
    }
    return null;
}

function catalogImageUrl(array $product): ?string
{
    global $domainhosts;
    $file = basename((string) ($product['storefront_image'] ?? ''));
    if ($file === '') {
        $candidate = basename((string) ($product['code_product'] ?? '')) . '.jpg';
        if ($candidate !== '.jpg' && is_file(__DIR__ . '/../../images/products/' . $candidate)) {
            $file = $candidate;
        }
    }
    if ($file === '' || !is_file(__DIR__ . '/../../images/products/' . $file)) {
        return null;
    }
    return 'https://' . $domainhosts . '/images/products/' . rawurlencode($file);
}

function catalogStoreUploadedImage(array $file, ?string $existing = null): ?string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return $existing;
    }
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
        throw new RuntimeException('image_upload_failed');
    }
    if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException('image_too_large');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException('image_invalid');
    }
    $size = @getimagesize($file['tmp_name']);
    if (!is_array($size) || (int) $size[0] < 32 || (int) $size[1] < 32 || (int) $size[0] > 2400 || (int) $size[1] > 2400) {
        throw new RuntimeException('image_dimensions');
    }
    $dir = __DIR__ . '/../../images/products';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('image_storage');
    }
    $filename = 'product-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
        throw new RuntimeException('image_storage');
    }
    @chmod($dir . '/' . $filename, 0644);
    return $filename;
}

function catalogAudit(PDO $pdo, string $action, int $productId, array $old, array $new): void
{
    $entry = [
        'time' => date('c'),
        'actor' => (string) ($_SESSION['admin_user'] ?? 'unknown'),
        'action' => $action,
        'product_id' => $productId,
        'old' => $old,
        'new' => $new,
    ];
    $file = __DIR__ . '/../../storage/catalog_audit.jsonl';
    @file_put_contents($file, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
}
