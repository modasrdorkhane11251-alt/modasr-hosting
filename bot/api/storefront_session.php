<?php

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';
require_once __DIR__ . '/../lib/miniapp_auth.php';

$data = storefront_signed_payload();
$action = (string) ($data['action'] ?? '');
$now = date('Y-m-d H:i:s');

try {
    if ($action === 'issue') {
        $userId = trim((string) ($data['user_id'] ?? ''));
        $user = $userId !== '' ? storefront_user($pdo, $userId) : null;
        if (!$user) storefront_json(false, 'user unavailable', [], 404);
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare("INSERT INTO miniapp_session (token, user_id, platform, created_at, expires_at) VALUES (?, ?, 'website', ?, ?)");
        $stmt->execute([hash('sha256', $token), $userId, $now, date('Y-m-d H:i:s', time() + 30 * 86400)]);
        storefront_json(true, 'session issued', [
            'token' => $token,
            'user' => ['telegram_id' => (string) $user['id'], 'username' => (string) $user['username']],
        ]);
    }
    $token = trim((string) ($data['token'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $token) && !preg_match('/^[a-f0-9]{40}$/', $token)) storefront_json(false, 'token invalid', [], 422);
    if ($action === 'consume') {
        $auth = miniapp_consume_auth_token($token);
        if (!$auth) storefront_json(false, 'token expired', [], 401);
        $user = storefront_user($pdo, (string) $auth['user_id']);
        if (!$user) storefront_json(false, 'user unavailable', [], 401);
        storefront_json(true, 'session exchanged', [
            'user' => ['telegram_id' => (string) $user['id'], 'username' => (string) $user['username']],
        ]);
    }
    $hash = hash('sha256', $token);
    if ($action === 'revoke') {
        $stmt = $pdo->prepare("DELETE FROM miniapp_session WHERE token = ? AND platform = 'website'");
        $stmt->execute([$hash]);
        storefront_json(true, 'session revoked');
    }
    if ($action !== 'resolve') storefront_json(false, 'action invalid', [], 422);
    $stmt = $pdo->prepare("SELECT user_id, expires_at FROM miniapp_session WHERE token = ? AND platform = 'website' LIMIT 1");
    $stmt->execute([$hash]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session || strtotime((string) $session['expires_at']) <= time()) {
        if ($session) {
            $stmt = $pdo->prepare("DELETE FROM miniapp_session WHERE token = ?");
            $stmt->execute([$hash]);
        }
        storefront_json(false, 'session expired', [], 401);
    }
    $user = storefront_user($pdo, (string) $session['user_id']);
    if (!$user) storefront_json(false, 'user unavailable', [], 401);
    storefront_json(true, 'session valid', [
        'user' => ['telegram_id' => (string) $user['id'], 'username' => (string) $user['username']],
        'expires_at' => $session['expires_at'],
    ]);
} catch (Throwable $e) {
    error_log('storefront session error: ' . $e->getMessage());
    storefront_json(false, 'server error', [], 500);
}
