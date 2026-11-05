<?php

require_once __DIR__ . '/sms_provider.php';
require_once __DIR__ . '/auth_account.php';

final class PhoneAuthException extends RuntimeException
{
    public function __construct(
        string $safeMessage,
        private int $httpStatus = 400,
        private array $responseObject = []
    ) {
        parent::__construct($safeMessage);
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function responseObject(): array
    {
        return $this->responseObject;
    }
}

/**
 * Normalize supported national Iranian input and international E.164 input.
 * International numbers must include + or 00; local non-Iranian formats are
 * rejected instead of being guessed with the wrong country.
 */
function phoneNormalizeE164(string $input): string
{
    $input = trim($input);
    if ($input === '' || strlen($input) > 64 || preg_match('/[\x00-\x1F\x7F]/', $input)) {
        throw new PhoneAuthException('phone_invalid', 422);
    }

    $input = strtr($input, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    if (!preg_match('/^[+0-9\s().-]+$/', $input)) {
        throw new PhoneAuthException('phone_invalid', 422);
    }

    $compact = preg_replace('/[\s().-]/', '', $input);
    if (str_starts_with($compact, '00')) {
        $compact = '+' . substr($compact, 2);
    }

    // Accepted Iranian forms: 09xxxxxxxxx, 9xxxxxxxxx, 98xxxxxxxxx,
    // 0098xxxxxxxxx, and +98xxxxxxxxx.
    if (preg_match('/^09\d{9}$/', $compact)) {
        $compact = '+98' . substr($compact, 1);
    } elseif (preg_match('/^9\d{9}$/', $compact)) {
        $compact = '+98' . $compact;
    } elseif (preg_match('/^989\d{9}$/', $compact)) {
        $compact = '+' . $compact;
    }

    if (!preg_match('/^\+[1-9]\d{7,14}$/', $compact)) {
        throw new PhoneAuthException('phone_invalid', 422);
    }
    if (str_starts_with($compact, '+98') && !preg_match('/^\+989\d{9}$/', $compact)) {
        throw new PhoneAuthException('phone_invalid', 422);
    }

    return $compact;
}

final class PhoneOtpService
{
    private array $config;
    private Closure $clock;
    private Closure $codeGenerator;
    private Closure $primaryResolver;

    public function __construct(
        private PDO $pdo,
        private ModasrSmsRouter $smsRouter,
        private string $hashKey,
        array $config = [],
        ?callable $clock = null,
        ?callable $codeGenerator = null,
        ?callable $primaryResolver = null
    ) {
        if (strlen($this->hashKey) < 32) {
            throw new PhoneAuthException('phone_otp_not_configured', 503);
        }

        $this->config = array_merge([
            'ttl' => 120,
            'max_attempts' => 5,
            'resend_cooldown' => 60,
            'phone_per_hour' => 5,
            'ip_per_hour' => 20,
            'ip_per_day' => 50,
        ], $config);
        foreach (['ttl', 'max_attempts', 'resend_cooldown', 'phone_per_hour', 'ip_per_hour', 'ip_per_day'] as $key) {
            if (!is_int($this->config[$key]) || $this->config[$key] <= 0) {
                throw new InvalidArgumentException('invalid phone OTP configuration');
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

    public function request(string $inputPhone, string $clientIp): array
    {
        $phone = phoneNormalizeE164($inputPhone);
        if (!str_starts_with($phone, '+98')) {
            throw new PhoneAuthException('foreign_email_required', 422);
        }
        $now = ($this->clock)();
        $ipHash = $this->privateHash('ip|' . $clientIp);
        $requestId = bin2hex(random_bytes(16));
        $code = ($this->codeGenerator)();
        if (!preg_match('/^\d{6}$/', $code)) {
            throw new RuntimeException('invalid code generator');
        }
        $providerName = $this->smsRouter->providerFor($phone)->name();
        $locks = $this->acquireRequestLocks($phone, $ipHash);

        try {
            $this->pdo->beginTransaction();
            try {
                $stmt = $this->pdo->prepare(
                    'SELECT resend_after FROM storefront_phone_otp WHERE phone_e164 = ? ORDER BY id DESC LIMIT 1 FOR UPDATE'
                );
                $stmt->execute([$phone]);
                $latest = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($latest && (int) $latest['resend_after'] > $now) {
                    throw new PhoneAuthException('rate_limited', 429, [
                        'retry_after' => (int) $latest['resend_after'] - $now,
                    ]);
                }

                $this->assertRequestBudget($phone, $ipHash, $now);

                // A newly requested code invalidates every earlier unused code
                // for this destination, preventing old-code replay after resend.
                $stmt = $this->pdo->prepare(
                    'UPDATE storefront_phone_otp SET superseded_at = ? WHERE phone_e164 = ? AND consumed_at IS NULL AND superseded_at IS NULL'
                );
                $stmt->execute([$now, $phone]);

                $stmt = $this->pdo->prepare(
                    'INSERT INTO storefront_phone_otp (request_id, phone_e164, code_hash, expires_at, attempts, resend_after, provider, provider_status, created_ip_hash, created_at) VALUES (?, ?, ?, ?, 0, ?, ?, \'pending\', ?, ?)'
                );
                $stmt->execute([
                    $requestId,
                    $phone,
                    $this->codeHash($requestId, $phone, $code),
                    $now + $this->config['ttl'],
                    $now + $this->config['resend_cooldown'],
                    $providerName,
                    $ipHash,
                    $now,
                ]);
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $e;
            }
        } finally {
            $this->releaseLocks($locks);
        }

        // No DB transaction or named lock is held during provider I/O. The mock
        // performs no network operations. Real adapters must honor requestId as
        // their idempotency key and return sent/failed/unknown.
        try {
            $message = "MODASR login code: {$code}. It expires in 2 minutes. If you did not request it, ignore this message.";
            $result = $this->smsRouter->providerFor($phone)->send($phone, $message, $requestId);
            $status = (string) ($result['status'] ?? 'unknown');
            if (!in_array($status, ['sent', 'failed', 'unknown'], true)) {
                $status = 'unknown';
            }
            $messageId = isset($result['message_id']) ? substr((string) $result['message_id'], 0, 128) : null;
        } catch (Throwable) {
            $status = 'unknown';
            $messageId = null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE storefront_phone_otp SET provider_status = ?, provider_message_id = ? WHERE request_id = ? AND provider_status = \'pending\''
        );
        $stmt->execute([$status, $messageId, $requestId]);

        if ($status !== 'sent') {
            throw new PhoneAuthException('sms_unavailable', 502);
        }

        return [
            'request_id' => $requestId,
            'expires_in' => $this->config['ttl'],
            'resend_after' => $this->config['resend_cooldown'],
        ];
    }

    public function verify(string $inputPhone, string $requestId, string $code): array
    {
        $phone = phoneNormalizeE164($inputPhone);
        $code = trim($code);
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId) || !preg_match('/^\d{6}$/', $code)) {
            throw new PhoneAuthException('invalid_or_expired_code', 401);
        }
        $now = ($this->clock)();

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM storefront_phone_otp WHERE request_id = ? AND phone_e164 = ? FOR UPDATE'
            );
            $stmt->execute([$requestId, $phone]);
            $otp = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$otp) {
                throw new PhoneAuthException('invalid_or_expired_code', 401);
            }
            if ($otp['consumed_at'] !== null || $otp['superseded_at'] !== null) {
                throw new PhoneAuthException('invalid_or_expired_code', 401);
            }
            if ((int) $otp['expires_at'] <= $now) {
                throw new PhoneAuthException('code_expired', 410);
            }
            if ((int) $otp['attempts'] >= $this->config['max_attempts']) {
                throw new PhoneAuthException('too_many_attempts', 429);
            }
            if ((string) $otp['provider_status'] !== 'sent') {
                throw new PhoneAuthException('invalid_or_expired_code', 401);
            }

            if (!hash_equals((string) $otp['code_hash'], $this->codeHash($requestId, $phone, $code))) {
                $stmt = $this->pdo->prepare(
                    'UPDATE storefront_phone_otp SET attempts = attempts + 1 WHERE id = ? AND consumed_at IS NULL AND superseded_at IS NULL AND attempts < ?'
                );
                $stmt->execute([(int) $otp['id'], $this->config['max_attempts']]);
                $this->pdo->commit();
                throw new PhoneAuthException('invalid_or_expired_code', 401);
            }

            $userId = $this->findOrCreatePhoneUser($phone, $now);
            $primaryId = (string) ($this->primaryResolver)($userId);
            if ($primaryId === '') {
                throw new RuntimeException('primary identity unavailable');
            }

            $stmt = $this->pdo->prepare('SELECT id, username, Balance, User_Status FROM user WHERE id = ? LIMIT 1');
            $stmt->execute([$primaryId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || (string) ($user['User_Status'] ?? 'Active') !== 'Active') {
                throw new PhoneAuthException('account_unavailable', 403);
            }

            // Conditional single-use consumption is the concurrency guard even
            // if a caller retries or two requests reach verification together.
            $stmt = $this->pdo->prepare(
                'UPDATE storefront_phone_otp SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL AND superseded_at IS NULL AND expires_at > ? AND attempts < ? AND provider_status = \'sent\''
            );
            $stmt->execute([$now, (int) $otp['id'], $now, $this->config['max_attempts']]);
            if ($stmt->rowCount() !== 1) {
                throw new PhoneAuthException('invalid_or_expired_code', 401);
            }

            $this->pdo->commit();
            if (function_exists('clearSelectCache')) {
                clearSelectCache('user');
            }

            return [
                'telegram_id' => (string) $user['id'],
                'username' => (string) ($user['username'] ?? ''),
                'balance' => (int) ($user['Balance'] ?? 0),
            ];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function findOrCreatePhoneUser(string $phone, int $now): string
    {
        return authFindOrCreateVerifiedUser($this->pdo, 'phone', $phone, $now);
    }

    private function assertRequestBudget(string $phone, string $ipHash, int $now): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM storefront_phone_otp WHERE phone_e164 = ? AND created_at >= ?');
        $stmt->execute([$phone, $now - 3600]);
        if ((int) $stmt->fetchColumn() >= $this->config['phone_per_hour']) {
            throw new PhoneAuthException('rate_limited', 429, ['retry_after' => 3600]);
        }

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM storefront_phone_otp WHERE created_ip_hash = ? AND created_at >= ?');
        $stmt->execute([$ipHash, $now - 3600]);
        if ((int) $stmt->fetchColumn() >= $this->config['ip_per_hour']) {
            throw new PhoneAuthException('rate_limited', 429, ['retry_after' => 3600]);
        }

        $stmt->execute([$ipHash, $now - 86400]);
        if ((int) $stmt->fetchColumn() >= $this->config['ip_per_day']) {
            throw new PhoneAuthException('rate_limited', 429, ['retry_after' => 86400]);
        }
    }

    private function acquireRequestLocks(string $phone, string $ipHash): array
    {
        $keys = ['phone|' . $phone, 'ip|' . $ipHash];
        sort($keys, SORT_STRING);
        $locks = [];
        foreach ($keys as $key) {
            $name = 'modasr_phone_' . substr(hash('sha256', $key), 0, 40);
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
                // The connection will release named locks when closed.
            }
        }
    }

    private function codeHash(string $requestId, string $phone, string $code): string
    {
        return $this->privateHash('otp|' . $requestId . '|' . $phone . '|' . $code);
    }

    private function privateHash(string $value): string
    {
        return hash_hmac('sha256', $value, $this->hashKey);
    }
}
