<?php

/** Small shared HTTP/HMAC helpers for new storefront APIs. */
function storefront_json(bool $ok, string $msg, array $obj = [], int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['status' => $ok, 'msg' => $msg, 'obj' => $obj], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function storefront_secret_value(): string
{
    $file = __DIR__ . '/storefront_secret.php';
    if (!is_file($file)) return '';
    $secret = include $file;
    return is_string($secret) ? trim($secret) : '';
}

function storefront_signed_payload(): array
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        storefront_json(false, 'method invalid', [], 405);
    }
    $raw = (string) file_get_contents('php://input');
    $timestamp = (string) ($_SERVER['HTTP_X_STOREFRONT_TIMESTAMP'] ?? '');
    $signature = (string) ($_SERVER['HTTP_X_STOREFRONT_SIGNATURE'] ?? '');
    $secret = storefront_secret_value();
    if ($secret === '') storefront_json(false, 'not configured', [], 503);
    if (!preg_match('/^\d{9,12}$/', $timestamp) || abs(time() - (int) $timestamp) > 300) {
        storefront_json(false, 'signature invalid', [], 403);
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
    if (!hash_equals($expected, $signature)) {
        storefront_json(false, 'signature invalid', [], 403);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) storefront_json(false, 'invalid json', [], 400);
    return $data;
}

function storefront_user(PDO $pdo, string $id): ?array
{
    $stmt = $pdo->prepare("SELECT id, username, User_Status FROM user WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user && ($user['User_Status'] ?? 'Active') === 'Active' ? $user : null;
}
