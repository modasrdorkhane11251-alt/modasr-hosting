<?php

/**
 * Populate exact USD-cent values for the products that already exist both in
 * the website's legacy static metadata and in the shared product table.
 *
 * Safety:
 *   - CLI only; dry-run by default.
 *   - No INSERT or DELETE; matching is by immutable code_product/slug.
 *   - Aborts when a mapped slug is missing or duplicated.
 *   - Apply only after migration 022 and a verified production backup:
 *       php scripts/storefront_sync_static.php --apply
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config.php';

$apply = in_array('--apply', $argv, true);
$map = json_decode((string) file_get_contents(__DIR__ . '/storefront_usd_map.json'), true);
if (!is_array($map) || $map === []) {
    fwrite(STDERR, "Invalid USD map.\n");
    exit(1);
}

$columns = $pdo->query("SHOW COLUMNS FROM product LIKE 'storefront_usd_cents'")->fetch();
if (!$columns) {
    fwrite(STDERR, "Migration 022 is required before synchronization.\n");
    exit(1);
}

$rows = $pdo->query("SELECT id, code_product, storefront_usd_cents FROM product")->fetchAll(PDO::FETCH_ASSOC);
$byCode = [];
foreach ($rows as $row) {
    $code = (string) $row['code_product'];
    if (!isset($byCode[$code])) {
        $byCode[$code] = [];
    }
    $byCode[$code][] = $row;
}

$changes = [];
$errors = [];
foreach ($map as $slug => $cents) {
    $cents = (int) $cents;
    if ($cents <= 0) {
        $errors[] = "Invalid cents for {$slug}";
        continue;
    }
    $matches = $byCode[$slug] ?? [];
    if (count($matches) !== 1) {
        $errors[] = "Expected exactly one DB row for {$slug}; found " . count($matches);
        continue;
    }
    $row = $matches[0];
    if ((int) $row['storefront_usd_cents'] !== $cents) {
        $changes[] = ['id' => (int) $row['id'], 'slug' => $slug, 'cents' => $cents];
    }
}

if ($errors) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo json_encode([
    'mode' => $apply ? 'apply' : 'dry-run',
    'mapped_products' => count($map),
    'changes_required' => count($changes),
], JSON_PRETTY_PRINT) . "\n";

if (!$apply || $changes === []) {
    exit(0);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("UPDATE product SET storefront_usd_cents = ? WHERE id = ? AND code_product = ?");
    foreach ($changes as $change) {
        $stmt->execute([$change['cents'], $change['id'], $change['slug']]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("Concurrent catalog change detected for {$change['slug']}");
        }
    }
    $pdo->commit();
    echo "Updated: " . count($changes) . "\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
