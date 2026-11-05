<?php

declare(strict_types=1);

function webHashToken(string $token): string
{
    $secret = (string) getenv('MODASR_RELAY_SECRET');
    if ($secret === '') {
        throw new RuntimeException('web_auth_config_invalid');
    }
    return hash_hmac('sha256', $token, $secret);
}

function webFingerprint(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return hash_hmac('sha256', $ip . '|' . $agent, (string) getenv('MODASR_RELAY_SECRET'));
}

function webIssueMagicToken(PDO $pdo, string $tenantKey, int $principalId, string $role): string
{
    if (!preg_match('/^[a-f0-9]{24}$/', $tenantKey) || !in_array($role, ['PLATFORM_OWNER', 'TENANT_OWNER'], true)) {
        throw new RuntimeException('web_magic_invalid');
    }
    $token = bin2hex(random_bytes(32));
    $stmt = $pdo->prepare('INSERT INTO hosting_web_magic_token (id, tenant_key, principal_id, role, token_hash, expires_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([HostingAuditLogger::uuid(), $tenantKey, $principalId, $role, webHashToken($token), time() + 600]);
    return $token;
}

function webConsumeMagicToken(PDO $pdo, string $token): array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException('web_magic_invalid');
    }
    $stmt = $pdo->prepare('UPDATE hosting_web_magic_token SET used_at = CURRENT_TIMESTAMP WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?');
    $stmt->execute([webHashToken($token), time()]);
    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException('web_magic_invalid');
    }
    $stmt = $pdo->prepare('SELECT * FROM hosting_web_magic_token WHERE token_hash = ?');
    $stmt->execute([webHashToken($token)]);
    $magic = $stmt->fetch();
    if (!is_array($magic)) {
        throw new RuntimeException('web_magic_invalid');
    }
    return $magic;
}

function webStartSession(PDO $pdo, array $magic): array
{
    $sessionToken = bin2hex(random_bytes(32));
    $csrfToken = bin2hex(random_bytes(32));
    $sessionId = HostingAuditLogger::uuid();
    $absoluteExpiry = time() + 7200;
    $stmt = $pdo->prepare(
        'INSERT INTO hosting_web_session
        (id, tenant_key, principal_id, role, token_hash, csrf_hash, ip_hash, user_agent_hash, last_seen_at, absolute_expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $sessionId,
        $magic['tenant_key'],
        (int) $magic['principal_id'],
        $magic['role'],
        webHashToken($sessionToken),
        webHashToken($csrfToken),
        webFingerprint(),
        webFingerprint(),
        time(),
        $absoluteExpiry,
    ]);
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $cookieOptions = [
        'expires' => $absoluteExpiry,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    setcookie('modasr_hosting_session', $sessionToken, $cookieOptions);
    setcookie('modasr_hosting_csrf', $csrfToken, $cookieOptions);
    return ['session_token' => $sessionToken, 'csrf_token' => $csrfToken];
}

function webContext(PDO $pdo): array
{
    $token = (string) ($_COOKIE['modasr_hosting_session'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        throw new RuntimeException('web_auth_required');
    }
    $stmt = $pdo->prepare('SELECT * FROM hosting_web_session WHERE token_hash = ? AND revoked_at IS NULL');
    $stmt->execute([webHashToken($token)]);
    $session = $stmt->fetch();
    if (!is_array($session)) {
        throw new RuntimeException('web_auth_required');
    }
    $now = time();
    if ((int) $session['last_seen_at'] < $now - 900 || (int) $session['absolute_expires_at'] < $now) {
        throw new RuntimeException('web_auth_required');
    }
    if (!hash_equals((string) $session['ip_hash'], webFingerprint()) || !hash_equals((string) $session['user_agent_hash'], webFingerprint())) {
        throw new RuntimeException('web_auth_required');
    }
    if ((int) $session['last_seen_at'] < $now - 60) {
        $update = $pdo->prepare('UPDATE hosting_web_session SET last_seen_at = ? WHERE id = ?');
        $update->execute([$now, $session['id']]);
    }
    $session['csrf_token'] = (string) ($_COOKIE['modasr_hosting_csrf'] ?? '');
    return $session;
}

function webVerifyCsrf(array $session): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(webHashToken($token), (string) $session['csrf_hash'])) {
        throw new RuntimeException('csrf_invalid');
    }
}

function webLogout(PDO $pdo): void
{
    $token = (string) ($_COOKIE['modasr_hosting_session'] ?? '');
    if (preg_match('/^[a-f0-9]{64}$/', $token)) {
        $stmt = $pdo->prepare('UPDATE hosting_web_session SET revoked_at = CURRENT_TIMESTAMP WHERE token_hash = ?');
        $stmt->execute([webHashToken($token)]);
    }
    foreach (['modasr_hosting_session', 'modasr_hosting_csrf'] as $name) {
        setcookie($name, '', time() - 3600, '/');
    }
}
