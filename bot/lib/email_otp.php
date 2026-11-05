<?php

require_once __DIR__ . '/phone_otp.php';
require_once __DIR__ . '/email_provider.php';

function emailNormalizeIdentity(string $input): string
{
    $email = strtolower(trim($input));
    if ($email === '' || strlen($email) > 254 || preg_match('/[\x00-\x1F\x7F]/', $email)) {
        throw new PhoneAuthException('email_invalid', 422);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email)) {
        throw new PhoneAuthException('email_invalid', 422);
    }
    return $email;
}

final class EmailOtpService
{
    private array $config;
    private Closure $clock;
    private Closure $codeGenerator;
    private Closure $primaryResolver;

    public function __construct(
        private PDO $pdo,
        private ModasrEmailProvider $emailProvider,
        private string $hashKey,
        array $config = [],
        ?callable $clock = null,
        ?callable $codeGenerator = null,
        ?callable $primaryResolver = null
    ) {
        if (strlen($this->hashKey) < 32) {
            throw new PhoneAuthException('email_otp_not_configured', 503);
        }
        $this->config = array_merge([
            'ttl' => 300,
            'max_attempts' => 5,
            'resend_cooldown' => 60,
            'email_per_hour' => 5,
            'ip_per_hour' => 20,
            'ip_per_day' => 50,
        ], $config);
        foreach (['ttl', 'max_attempts', 'resend_cooldown', 'email_per_hour', 'ip_per_hour', 'ip_per_day'] as $key) {
            if (!is_int($this->config[$key]) || $this->config[$key] <= 0) {
                throw new InvalidArgumentException('invalid email OTP configuration');
            }
        }
        $this->clock = $clock !== null ? Closure::fromCallable($clock) : static fn(): int => time();
        $this->codeGenerator = $codeGenerator !== null
            ? Closure::fromCallable($codeGenerator)
            : static fn(): string => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->primaryResolver = $primaryResolver !== null
            ? Closure::fromCallable($primaryResolver)
            : static function (string $userId): string {
                return function_exists('modasr_primary_user_id') ? modasr_primary_user_id($userId) : $userId;
            };
    }

    public function request(string $inputEmail, string $clientIp): array
    {
        $email = emailNormalizeIdentity($inputEmail);
        $now = ($this->clock)();
        $ipHash = $this->privateHash('ip|' . $clientIp);
        $requestId = bin2hex(random_bytes(16));
        $code = ($this->codeGenerator)();
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('invalid code generator');
        }
        $locks = $this->acquireRequestLocks($email, $ipHash);

        try {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare('SELECT resend_after FROM storefront_email_otp WHERE email = ? ORDER BY id DESC LIMIT 1 FOR UPDATE');
                $stmt->execute([$email]);
                $latest = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($latest && (int) $latest['resend_after'] > $now) {
                    throw new PhoneAuthException('rate_limited', 429, ['retry_after' => (int) $latest['resend_after'] - $now]);
                }
                $this->assertRequestBudget($email, $ipHash, $now);

                $stmt = $this->pdo->prepare('UPDATE storefront_email_otp SET superseded_at = ? WHERE email = ? AND consumed_at IS NULL AND superseded_at IS NULL');
                $stmt->execute([$now, $email]);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO storefront_email_otp (request_id, email, code_hash, expires_at, attempts, resend_after, provider, provider_status, created_ip_hash, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, \'pending\', ?, ?)'
                );
                $stmt->execute([
                    $requestId,
                    $email,
                    $this->codeHash($requestId, $email, $code),
                    $now + $this->config['ttl'],
                    $now + $this->config['resend_cooldown'],
                    $this->emailProvider->name(),
                    $ipHash,
                    $now,
                ]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
        } finally {
            $this->releaseLocks($locks);
        }

        try {
            $subject = 'MODASR login code';
            $message = "Your MODASR login code is: {$code}\n\nIt expires in 5 minutes. If you did not request it, ignore this email.";
            $result = $this->emailProvider->send($email, $subject, $message, $requestId);
            $status = (string) ($result['status'] ?? 'unknown');
            if (!in_array($status, ['sent', 'failed', 'unknown'], true)) $status = 'unknown';
            $messageId = isset($result['message_id']) ? substr((string) $result['message_id'], 0, 128) : null;
        } catch (Throwable) {
            $status = 'unknown';
            $messageId = null;
        }

        $stmt = $this->pdo->prepare('UPDATE storefront_email_otp SET provider_status = ?, provider_message_id = ? WHERE request_id = ? AND provider_status = \'pending\'');
        $stmt->execute([$status, $messageId, $requestId]);
        if ($status !== 'sent') {
            throw new PhoneAuthException('email_unavailable', 502);
        }

        return ['request_id' => $requestId, 'expires_in' => $this->config['ttl'], 'resend_after' => $this->config['resend_cooldown']];
    }

    public function verify(string $inputEmail, string $requestId, string $code): array
    {
        $email = emailNormalizeIdentity($inputEmail);
        $code = trim($code);
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId) || !preg_match('/^\d{6}$/', $code)) {
            throw new PhoneAuthException('invalid_or_expired_code', 401);
        }
        $now = ($this->clock)();

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM storefront_email_otp WHERE request_id = ? AND email = ? FOR UPDATE');
            $stmt->execute([$requestId, $email]);
            $otp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$otp || $otp['consumed_at'] !== null || $otp['superseded_at'] !== null) {
                throw new PhoneAuthException('invalid_or_expired_code', 401);
            }
            if ((int) $otp['expires_at'] <= $now) throw new PhoneAuthException('code_expired', 410);
            if ((int) $otp['attempts'] >= $this->config['max_attempts']) throw new PhoneAuthException('too_many_attempts', 429);
            if ((string) $otp['provider_status'] !== 'sent') throw new PhoneAuthException('invalid_or_expired_code', 401);

            if (!hash_equals((string) $otp['code_hash'], $this->codeHash($requestId, $email, $code))) {
                $stmt = $this->pdo->prepare('UPDATE storefront_email_otp SET attempts = attempts + 1 WHERE id = ? AND consumed_at IS NULL AND superseded_at IS NULL AND attempts < ?');
                $stmt->execute([(int) $otp['id'], $this->config['max_attempts']]);
                $this->pdo->commit();
                throw new PhoneAuthException('invalid_or_expired_code', 401);
            }

            $userId = $this->findOrCreateEmailUser($email, $now);
            $primaryId = (string) ($this->primaryResolver)($userId);
            if ($primaryId === '') throw new RuntimeException('primary identity unavailable');

            $stmt = $this->pdo->prepare('SELECT id, username, Balance, User_Status FROM user WHERE id = ? LIMIT 1');
            $stmt->execute([$primaryId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || (string) ($user['User_Status'] ?? 'Active') !== 'Active') {
                throw new PhoneAuthException('account_unavailable', 403);
            }

            $stmt = $this->pdo->prepare('UPDATE storefront_email_otp SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL AND superseded_at IS NULL AND expires_at > ? AND attempts < ? AND provider_status = \'sent\'');
            $stmt->execute([$now, (int) $otp['id'], $now, $this->config['max_attempts']]);
            if ($stmt->rowCount() !== 1) throw new PhoneAuthException('invalid_or_expired_code', 401);

            $this->pdo->commit();
            if (function_exists('clearSelectCache')) clearSelectCache('user');

            return [
                'telegram_id' => (string) $user['id'],
                'username' => (string) ($user['username'] ?? ''),
                'balance' => (int) ($user['Balance'] ?? 0),
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private function findOrCreateEmailUser(string $email, int $now): string
    {
        return authFindOrCreateVerifiedUser($this->pdo, 'email', $email, $now);
    }

    private function assertRequestBudget(string $email, string $ipHash, int $now): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM storefront_email_otp WHERE email = ? AND created_at >= ?');
        $stmt->execute([$email, $now - 3600]);
        if ((int) $stmt->fetchColumn() >= $this->config['email_per_hour']) {
            throw new PhoneAuthException('rate_limited', 429, ['retry_after' => 3600]);
        }
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM storefront_email_otp WHERE created_ip_hash = ? AND created_at >= ?');
        $stmt->execute([$ipHash, $now - 3600]);
        if ((int) $stmt->fetchColumn() >= $this->config['ip_per_hour']) {
            throw new PhoneAuthException('rate_limited', 429, ['retry_after' => 3600]);
        }
        $stmt->execute([$ipHash, $now - 86400]);
        if ((int) $stmt->fetchColumn() >= $this->config['ip_per_day']) {
            throw new PhoneAuthException('rate_limited', 429, ['retry_after' => 86400]);
        }
    }

    private function acquireRequestLocks(string $email, string $ipHash): array
    {
        $keys = ['email|' . $email, 'ip|' . $ipHash];
        sort($keys, SORT_STRING);
        $locks = [];
        foreach ($keys as $key) {
            $name = 'modasr_email_' . substr(hash('sha256', $key), 0, 40);
            $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
            $stmt->execute([$name]);
            if ((int) $stmt->fetchColumn() !== 1) {
                $this->releaseLocks($locks);
                throw new PhoneAuthException('auth_busy', 503);
            }
            $locks[] = $name;
        }
        return $locks;
    }

    private function releaseLocks(array $locks): void
    {
        foreach (array_reverse($locks) as $name) {
            try {
                $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
                $stmt->execute([$name]);
            } catch (Throwable) {
            }
        }
    }

    private function codeHash(string $requestId, string $email, string $code): string
    {
        return $this->privateHash('email_otp|' . $requestId . '|' . $email . '|' . $code);
    }

    private function privateHash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hashKey);
    }
}
