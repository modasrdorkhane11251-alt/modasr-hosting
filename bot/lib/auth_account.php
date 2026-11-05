<?php

/**
 * Provision a MODASR account only after a channel has verified ownership.
 *
 * The caller must invoke this inside the successful OTP transaction. Requesting
 * a code never calls this function. Unique identity keys are the final guard
 * against duplicate phone/email bindings under concurrent verification.
 */
function authFindOrCreateVerifiedUser(PDO $pdo, string $channel, string $identifier, int $now): string
{
    $config = match ($channel) {
        'phone' => ['table' => 'storefront_phone_identity', 'column' => 'phone_e164'],
        'email' => ['table' => 'storefront_email_identity', 'column' => 'email'],
        default => throw new InvalidArgumentException('unsupported auth channel'),
    };
    $table = $config['table'];
    $column = $config['column'];

    $stmt = $pdo->prepare("SELECT user_id FROM {$table} WHERE {$column} = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$identifier]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return (string) $existing['user_id'];
    }

    // While Google remains an optional login method, this prevents a second
    // account for the same verified mailbox. It is identity deduplication, not
    // a legacy-user migration or an automatic wallet/account merge.
    if ($channel === 'email') {
        $stmt = $pdo->prepare('SELECT id FROM user WHERE google_email = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$identifier]);
        $googleUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($googleUser) {
            $userId = (string) $googleUser['id'];
            authAssertUserHasNoDifferentIdentity($pdo, $table, $column, $userId, $identifier);
            $stmt = $pdo->prepare("INSERT INTO {$table} ({$column}, user_id, created_at) VALUES (?, ?, ?)");
            $stmt->execute([$identifier, $userId, $now]);
            return $userId;
        }
    }

    for ($attempt = 0; $attempt < 5; $attempt++) {
        // Keep the existing numeric ten-digit MODASR ID contract used by the
        // order/payment APIs. random_int is cryptographically secure and the
        // primary-key collision check protects against accidental reuse.
        $userId = (string) random_int(1000000000, 9999999999);
        $stmt = $pdo->prepare('SELECT id FROM user WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            continue;
        }

        $username = $channel . '_' . substr(hash('sha256', $identifier), 0, 16);
        $number = $channel === 'phone' ? $identifier : 'none';
        $stmt = $pdo->prepare(
            "INSERT INTO user (id, platform, roll_Status, Processing_value, Processing_value_one, Processing_value_tow, Processing_value_four, step, limit_usertest, User_Status, number, Balance, pagenumber, username, agent, message_count, last_message_time, affiliates, affiliatescount, cardpayment, number_username, namecustom, register, verify, codeInvitation, pricediscount, maxbuyagent, joinchannel, score, status_cron) VALUES (:id, '{$channel}', 0, 'none', 'none', 'none', 'none', 'none', 0, 'Active', :number, 0, 1, :username, 'f', 0, 0, 0, 0, 0, 100, 'none', :register, 1, :invitation, 0, 0, 0, 0, 1)"
        );
        $stmt->execute([
            ':id' => $userId,
            ':number' => $number,
            ':username' => $username,
            ':register' => date('Y-m-d H:i:s', $now),
            ':invitation' => bin2hex(random_bytes(6)),
        ]);

        $stmt = $pdo->prepare("INSERT INTO {$table} ({$column}, user_id, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$identifier, $userId, $now]);
        return $userId;
    }

    throw new RuntimeException('verified user create failed');
}

function authAssertUserHasNoDifferentIdentity(PDO $pdo, string $table, string $column, string $userId, string $identifier): void
{
    // Table and column come only from the fixed channel map above.
    $stmt = $pdo->prepare("SELECT {$column} FROM {$table} WHERE user_id = ? LIMIT 1 FOR UPDATE");
    $stmt->execute([$userId]);
    $bound = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($bound && (string) $bound[$column] !== $identifier) {
        throw new PhoneAuthException('account_link_required', 409);
    }
}
