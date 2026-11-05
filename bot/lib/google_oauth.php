<?php

/** Google identity validation and account provisioning for web login. */

function googleValidateClaims(array $claims, string $clientId): array
{
    $sub = trim((string) ($claims['sub'] ?? ''));
    $email = strtolower(trim((string) ($claims['email'] ?? '')));
    // Google `sub` is the stable account identifier. `email_verified` can
    // legitimately vary and is not used to authorize or reject the login.
    if ($sub === '' || ($claims['aud'] ?? '') !== $clientId || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('google_identity_invalid');
    }
    return [
        'sub' => $sub,
        'email' => $email,
        'name' => trim((string) ($claims['name'] ?? '') ?: strstr($email, '@', true)),
    ];
}

function googleUpsertUser(PDO $pdo, array $claims, string $clientId): string
{
    $identity = googleValidateClaims($claims, $clientId);
    $stmt = $pdo->prepare("SELECT id FROM user WHERE google_sub = ? LIMIT 1");
    $stmt->execute([$identity['sub']]);
    $existingId = $stmt->fetchColumn();
    if ($existingId) {
        $stmt = $pdo->prepare("UPDATE user SET google_email = ?, display_name = ? WHERE id = ?");
        $stmt->execute([$identity['email'], $identity['name'] !== '' ? $identity['name'] : $identity['email'], $existingId]);
        clearSelectCache('user');
        return (string) $existingId;
    }

    $stmt = $pdo->prepare("SELECT id FROM user WHERE google_email = ? LIMIT 1");
    $stmt->execute([$identity['email']]);
    if ($stmt->fetch()) {
        throw new RuntimeException('google_email_exists');
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $id = (string) random_int(1000000000, 9999999999);
        $stmt = $pdo->prepare("SELECT id FROM user WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        if ($stmt->fetch()) continue;
        $username = preg_replace('/[^a-zA-Z0-9_]/', '', strstr($identity['email'], '@', true)) ?: 'google_user';
        $username = substr($username, 0, 40);
        $stmt = $pdo->prepare("INSERT INTO user (id, platform, step, limit_usertest, User_Status, number, Balance, pagenumber, username, agent, message_count, last_message_time, affiliates, affiliatescount, cardpayment, number_username, namecustom, register, verify, codeInvitation, pricediscount, maxbuyagent, joinchannel, score, status_cron, display_name, google_sub, google_email) VALUES (:id, 'google', 'none', 0, 'Active', 'none', 0, 1, :username, 'f', 0, 0, 0, 0, 1, 100, 'none', :register, 1, :code, 0, 0, 0, 0, 1, :display_name, :google_sub, :google_email)");
        $stmt->execute([
            ':id' => $id,
            ':username' => $username,
            ':register' => date('Y-m-d H:i:s'),
            ':code' => bin2hex(random_bytes(6)),
            ':display_name' => $identity['name'] !== '' ? $identity['name'] : $identity['email'],
            ':google_sub' => $identity['sub'],
            ':google_email' => $identity['email'],
        ]);
        clearSelectCache('user');
        return $id;
    }
    throw new RuntimeException('google_user_create_failed');
}
