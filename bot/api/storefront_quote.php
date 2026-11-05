<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';
require_once __DIR__ . '/../lib/storefront_core.php';

$data = storefront_signed_payload();
$userId = trim((string) ($data['user_id'] ?? ''));
if ($userId === '' || !storefront_user($pdo, $userId)) storefront_json(false, 'user unavailable', [], 404);
try {
    $items = $data['items'] ?? [];
    if (!is_array($items) || count($items) === 0 || count($items) > 20) storefront_json(false, 'items invalid', [], 422);
    $quote = storefrontQuote($pdo, $items, strtoupper(trim((string) ($data['discount_code'] ?? ''))), $userId);
    storefront_json(true, 'Successful', $quote);
} catch (InvalidArgumentException $e) {
    storefront_json(false, $e->getMessage(), [], 422);
} catch (Throwable $e) {
    error_log('storefront quote error: ' . $e->getMessage());
    storefront_json(false, 'server error', [], 500);
}
