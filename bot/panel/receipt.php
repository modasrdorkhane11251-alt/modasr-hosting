<?php
require_once __DIR__ . '/inc/config.php';
require_auth();

$id = (int) ($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT file_path, mime FROM storefront_receipt WHERE id = ? LIMIT 1");
$stmt->execute([$id]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$receipt) {
    http_response_code(404);
    exit('not found');
}
$base = realpath('/var/www/modasr-private/receipts');
$path = realpath((string) $receipt['file_path']);
if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit('not found');
}
header('Content-Type: ' . $receipt['mime']);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
