<?php

/** Standalone isolated test: fake PDO and in-memory mock SMS only. */
require_once __DIR__ . '/../lib/phone_otp.php';

final class PhoneFakeStatement extends PDOStatement
{
    private array $results = [];
    private int $rows = 0;

    public function __construct(private string $sql, private PhoneFakePDO $db) {}

    public function execute(?array $params = null): bool
    {
        $this->results = $this->db->route($this->sql, $params ?: [], $this->rows);
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->results[0] ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->results[0] ?? null;
        return $row ? array_values($row)[$column] : false;
    }

    public function rowCount(): int
    {
        return $this->rows;
    }
}

final class PhoneFakePDO extends PDO
{
    public array $otps = [];
    public array $identities = [];
    public array $users = [];
    public bool $lockAvailable = true;
    public bool $consumeBeforeUpdate = false;
    public bool $userInsertColumnMismatch = false;
    public int $nextOtpId = 1;
    private bool $transaction = false;
    private array $snapshot = [];

    public function __construct(array $users = [])
    {
        $this->users = $users;
    }

    public function prepare($sql, $options = []): PhoneFakeStatement
    {
        return new PhoneFakeStatement($sql, $this);
    }

    public function beginTransaction(): bool
    {
        $this->snapshot = [$this->otps, $this->identities, $this->users];
        $this->transaction = true;
        return true;
    }

    public function commit(): bool
    {
        $this->snapshot = [];
        $this->transaction = false;
        return true;
    }

    public function rollBack(): bool
    {
        [$this->otps, $this->identities, $this->users] = $this->snapshot;
        $this->snapshot = [];
        $this->transaction = false;
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->transaction;
    }

    public function route(string $sql, array $params, int &$rowCount): array
    {
        $rowCount = 0;
        $v = array_values($params);

        if (str_contains($sql, 'SELECT GET_LOCK')) {
            return [['result' => $this->lockAvailable ? 1 : 0]];
        }
        if (str_contains($sql, 'SELECT RELEASE_LOCK')) {
            return [['result' => 1]];
        }
        if (str_contains($sql, 'SELECT resend_after FROM storefront_phone_otp')) {
            $rows = array_values(array_filter($this->otps, fn($o) => $o['phone_e164'] === $v[0]));
            usort($rows, fn($a, $b) => $b['id'] <=> $a['id']);
            return array_slice($rows, 0, 1);
        }
        if (str_contains($sql, 'SELECT COUNT(*) FROM storefront_phone_otp WHERE phone_e164')) {
            $count = count(array_filter($this->otps, fn($o) => $o['phone_e164'] === $v[0] && $o['created_at'] >= $v[1]));
            return [['count' => $count]];
        }
        if (str_contains($sql, 'SELECT COUNT(*) FROM storefront_phone_otp WHERE created_ip_hash')) {
            $count = count(array_filter($this->otps, fn($o) => $o['created_ip_hash'] === $v[0] && $o['created_at'] >= $v[1]));
            return [['count' => $count]];
        }
        if (str_contains($sql, 'UPDATE storefront_phone_otp SET superseded_at')) {
            foreach ($this->otps as &$otp) {
                if ($otp['phone_e164'] === $v[1] && $otp['consumed_at'] === null && $otp['superseded_at'] === null) {
                    $otp['superseded_at'] = $v[0];
                    $rowCount++;
                }
            }
            return [];
        }
        if (str_contains($sql, 'INSERT INTO storefront_phone_otp')) {
            $this->otps[] = [
                'id' => $this->nextOtpId++, 'request_id' => $v[0], 'phone_e164' => $v[1],
                'code_hash' => $v[2], 'expires_at' => $v[3], 'attempts' => 0,
                'resend_after' => $v[4], 'provider' => $v[5], 'provider_message_id' => null,
                'provider_status' => 'pending', 'created_ip_hash' => $v[6], 'created_at' => $v[7],
                'consumed_at' => null, 'superseded_at' => null,
            ];
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'UPDATE storefront_phone_otp SET provider_status')) {
            foreach ($this->otps as &$otp) {
                if ($otp['request_id'] === $v[2] && $otp['provider_status'] === 'pending') {
                    $otp['provider_status'] = $v[0];
                    $otp['provider_message_id'] = $v[1];
                    $rowCount = 1;
                }
            }
            return [];
        }
        if (str_contains($sql, 'SELECT * FROM storefront_phone_otp WHERE request_id')) {
            return array_values(array_filter($this->otps, fn($o) => $o['request_id'] === $v[0] && $o['phone_e164'] === $v[1]));
        }
        if (str_contains($sql, 'UPDATE storefront_phone_otp SET attempts = attempts + 1')) {
            foreach ($this->otps as &$otp) {
                if ($otp['id'] === $v[0] && $otp['consumed_at'] === null && $otp['superseded_at'] === null && $otp['attempts'] < $v[1]) {
                    $otp['attempts']++;
                    $rowCount = 1;
                }
            }
            return [];
        }
        if (str_contains($sql, 'SELECT user_id FROM storefront_phone_identity')) {
            return array_values(array_filter($this->identities, fn($i) => $i['phone_e164'] === $v[0]));
        }
        if (str_contains($sql, 'INSERT INTO user')) {
            if (preg_match('/INSERT INTO user \((.*?)\) VALUES \((.*?)\)/s', $sql, $m)) {
                $this->userInsertColumnMismatch = substr_count($m[1], ',') !== substr_count($m[2], ',');
            }
            $id = $params[':id'];
            $this->users[$id] = [
                'id' => $id, 'platform' => 'phone', 'number' => $params[':number'],
                'username' => $params[':username'], 'Balance' => 0, 'User_Status' => 'Active', 'agent' => 'f',
            ];
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'INSERT INTO storefront_phone_identity')) {
            foreach ($this->identities as $identity) {
                if ($identity['phone_e164'] === $v[0] || $identity['user_id'] === $v[1]) {
                    throw new RuntimeException('duplicate phone identity');
                }
            }
            $this->identities[] = ['phone_e164' => $v[0], 'user_id' => $v[1], 'created_at' => $v[2]];
            $rowCount = 1;
            return [];
        }
        if (str_contains($sql, 'FROM user WHERE id = ?')) {
            $user = $this->users[$v[0]] ?? null;
            return $user ? [$user] : [];
        }
        if (str_contains($sql, 'UPDATE storefront_phone_otp SET consumed_at')) {
            foreach ($this->otps as &$otp) {
                if ($otp['id'] !== $v[1]) continue;
                // Simulate a competing transaction winning after our SELECT and
                // before our conditional UPDATE. The second update must fail.
                if ($this->consumeBeforeUpdate) {
                    $otp['consumed_at'] = $v[0] - 1;
                    $this->consumeBeforeUpdate = false;
                }
                if ($otp['consumed_at'] === null && $otp['superseded_at'] === null && $otp['expires_at'] > $v[2] && $otp['attempts'] < $v[3] && $otp['provider_status'] === 'sent') {
                    $otp['consumed_at'] = $v[0];
                    $rowCount = 1;
                }
            }
            return [];
        }

        throw new RuntimeException('Unhandled fake SQL: ' . $sql);
    }
}

$passed = 0;
$failed = 0;
function phone_ok(bool $condition, string $label): void
{
    global $passed, $failed;
    if ($condition) { $passed++; echo "  [PASS] {$label}\n"; }
    else { $failed++; echo "  [FAIL] {$label}\n"; }
}
function phone_throws(callable $callback, string $message): bool
{
    try { $callback(); return false; }
    catch (PhoneAuthException $e) { return $e->getMessage() === $message; }
}
function phone_service(PhoneFakePDO $db, ModasrSmsRouter $router, ?int &$now = null, array $config = [], ?callable $resolver = null): PhoneOtpService
{
    static $defaultNow = 1700000000;
    if ($now === null) $now = $defaultNow;
    return new PhoneOtpService(
        $db,
        $router,
        str_repeat('development-only-key-', 2),
        $config,
        static function () use (&$now): int { return $now; },
        static fn() => '012345',
        $resolver
    );
}
function phone_router(&$iran, &$international, string $iranOutcome = 'sent', string $internationalOutcome = 'sent'): ModasrSmsRouter
{
    $iran = new ModasrMockSmsProvider('mock_iran', $iranOutcome);
    $international = new ModasrMockSmsProvider('mock_international', $internationalOutcome);
    return new ModasrSmsRouter($iran, $international);
}

// Normalization and validation.
foreach (['09121234567', '9121234567', '989121234567', '00989121234567', '+98 912 123 4567', '۰۹۱۲۱۲۳۴۵۶۷'] as $input) {
    phone_ok(phoneNormalizeE164($input) === '+989121234567', 'Iranian normalization: ' . $input);
}
phone_ok(phoneNormalizeE164('+1 415 555 0123') === '+14155550123', 'international E.164 normalization');
phone_ok(phoneNormalizeE164('0044 7911 123456') === '+447911123456', 'international 00 prefix normalization');
phone_ok(phone_throws(fn() => phoneNormalizeE164('4155550123'), 'phone_invalid'), 'non-Iranian local number rejected');
phone_ok(phone_throws(fn() => phoneNormalizeE164('+98121234567'), 'phone_invalid'), 'non-mobile Iranian number rejected');
phone_ok(phone_throws(fn() => phoneNormalizeE164('+1-415-ABC-0123'), 'phone_invalid'), 'letters are not silently discarded');

// Iranian request, hashed storage, wrong code, registration, and replay.
$db = new PhoneFakePDO();
$router = phone_router($iran, $international);
$now = 1700000000;
$service = phone_service($db, $router, $now);
$request = $service->request('09121234567', '192.0.2.10');
phone_ok($request['expires_in'] === 120, 'phone OTP expires in exactly 120 seconds');
phone_ok(count($iran->outbox) === 1 && count($international->outbox) === 0, 'Iranian number uses only Iranian mock provider');
phone_ok(!str_contains(json_encode($db->otps), '012345'), 'database stores no plaintext OTP');
phone_ok(count($db->users) === 0 && count($db->identities) === 0, 'requesting a code creates no account or identity binding');
phone_ok(phone_throws(fn() => $service->verify('+989121234567', $request['request_id'], '999999'), 'invalid_or_expired_code'), 'incorrect code rejected');
phone_ok(count($db->users) === 0 && count($db->identities) === 0, 'incorrect code still creates no account');
phone_ok($db->otps[0]['attempts'] === 1, 'incorrect code increments attempt counter');
$user = $service->verify('+989121234567', $request['request_id'], '012345');
phone_ok(ctype_digit($user['telegram_id']) && strlen($user['telegram_id']) === 10, 'phone registration creates compatible user identity');
$newPhoneUser = $db->users[$user['telegram_id']];
phone_ok($newPhoneUser['platform'] === 'phone' && $newPhoneUser['number'] === '+989121234567', 'phone identity uses distinct platform and E.164 binding');
phone_ok($db->userInsertColumnMismatch === false, 'phone user insert column/value counts match');
phone_ok(phone_throws(fn() => $service->verify('+989121234567', $request['request_id'], '012345'), 'invalid_or_expired_code'), 'consumed code cannot be replayed');
phone_ok(count($db->users) === 1 && count($db->identities) === 1, 'replay cannot duplicate user or phone binding');

// Existing phone binding logs into the same account after a resend.
$now += 61;
$second = $service->request('+989121234567', '192.0.2.10');
$again = $service->verify('+989121234567', $second['request_id'], '012345');
phone_ok($again['telegram_id'] === $user['telegram_id'] && count($db->users) === 1, 'existing phone identity reuses existing account');
phone_ok(phone_throws(fn() => $service->verify('+989121234567', $request['request_id'], '012345'), 'invalid_or_expired_code'), 'old request cannot be replayed after resend');

// Expiration and resend invalidation on a separate account.
$db2 = new PhoneFakePDO();
$router2 = phone_router($iran2, $international2);
$now2 = 1700000000;
$service2 = phone_service($db2, $router2, $now2);
phone_ok(phone_throws(fn() => $service2->request('+14155550123', '192.0.2.20'), 'foreign_email_required'), 'international phone is directed to email instead of SMS');
phone_ok(count($db2->otps) === 0 && count($iran2->outbox) === 0 && count($international2->outbox) === 0, 'international phone creates no OTP or SMS attempt');
$expiring = $service2->request('+989222222222', '192.0.2.20');
phone_ok(count($iran2->outbox) === 1 && count($international2->outbox) === 0, 'Iranian number uses only Iranian mock provider');
$now2 += 301;
phone_ok(phone_throws(fn() => $service2->verify('+989222222222', $expiring['request_id'], '012345'), 'code_expired'), 'expired code rejected');

// Cooldown, per-phone budget, and per-IP SMS-pumping budget.
$db3 = new PhoneFakePDO();
$router3 = phone_router($iran3, $international3);
$now3 = 1700000000;
$service3 = phone_service($db3, $router3, $now3, ['phone_per_hour' => 2, 'ip_per_hour' => 2, 'ip_per_day' => 2]);
$service3->request('09121234567', '192.0.2.30');
phone_ok(phone_throws(fn() => $service3->request('09121234567', '192.0.2.30'), 'rate_limited'), 'resend cooldown enforced');
$now3 += 61;
$service3->request('09121234567', '192.0.2.30');
$now3 += 61;
phone_ok(phone_throws(fn() => $service3->request('09121234567', '192.0.2.30'), 'rate_limited'), 'per-phone hourly budget enforced');
phone_ok(phone_throws(fn() => $service3->request('+989222222222', '192.0.2.30'), 'rate_limited'), 'per-IP budget enforced across destinations');

// Maximum verification attempts.
$db4 = new PhoneFakePDO();
$router4 = phone_router($iran4, $international4);
$now4 = 1700000000;
$service4 = phone_service($db4, $router4, $now4);
$limited = $service4->request('+989222222222', '192.0.2.40');
for ($i = 0; $i < 5; $i++) {
    phone_throws(fn() => $service4->verify('+989222222222', $limited['request_id'], '999999'), 'invalid_or_expired_code');
}
phone_ok(phone_throws(fn() => $service4->verify('+989222222222', $limited['request_id'], '012345'), 'too_many_attempts'), 'maximum verification attempts enforced');

// Account linking resolves the primary MODASR identity without merging or
// modifying the existing account-link implementation.
$primary = ['id' => '1000000001', 'platform' => 'telegram', 'number' => 'none', 'username' => 'primary', 'Balance' => 12500, 'User_Status' => 'Active', 'agent' => 'f'];
$db5 = new PhoneFakePDO(['1000000001' => $primary]);
$router5 = phone_router($iran5, $international5);
$now5 = 1700000000;
$service5 = phone_service($db5, $router5, $now5, [], static fn(string $id): string => '1000000001');
$linkedRequest = $service5->request('09121234567', '192.0.2.50');
$linkedUser = $service5->verify('+989121234567', $linkedRequest['request_id'], '012345');
$phoneIdentity = $db5->identities[0]['user_id'];
phone_ok($linkedUser['telegram_id'] === '1000000001' && $linkedUser['balance'] === 12500, 'linked phone login resolves primary account and wallet view');
phone_ok($phoneIdentity !== '1000000001' && $db5->users[$phoneIdentity]['platform'] === 'phone', 'phone binding remains a linkable secondary identity');
phone_ok($db5->users['1000000001']['Balance'] === 12500, 'phone login performs no wallet mutation');

// Simulated concurrent verification: a competitor consumes first; the second
// conditional update must fail and its user-creation transaction must roll back.
$db6 = new PhoneFakePDO();
$router6 = phone_router($iran6, $international6);
$now6 = 1700000000;
$service6 = phone_service($db6, $router6, $now6);
$race = $service6->request('+989222222222', '192.0.2.60');
$db6->consumeBeforeUpdate = true;
phone_ok(phone_throws(fn() => $service6->verify('+989222222222', $race['request_id'], '012345'), 'invalid_or_expired_code'), 'concurrent verification has one atomic winner');
phone_ok(count($db6->users) === 0 && count($db6->identities) === 0, 'losing concurrent verification rolls back new identity writes');

// Named request locks reject overlapping request processing before insert.
$db7 = new PhoneFakePDO();
$router7 = phone_router($iran7, $international7);
$now7 = 1700000000;
$service7 = phone_service($db7, $router7, $now7);
$db7->lockAvailable = false;
phone_ok(phone_throws(fn() => $service7->request('+989222222222', '192.0.2.70'), 'auth_busy'), 'concurrent OTP requests are serialized by named locks');
phone_ok(count($db7->otps) === 0 && count($international7->outbox) === 0, 'busy request creates neither OTP nor SMS attempt');

// Provider failure/unknown is auditable, cannot verify, and is not retried.
foreach (['failed', 'unknown'] as $outcome) {
    $dbx = new PhoneFakePDO();
    $routerx = phone_router($iranx, $internationalx, $outcome, 'sent');
    $nowx = 1700000000;
    $servicex = phone_service($dbx, $routerx, $nowx);
    $caught = null;
    try { $servicex->request('+989222222222', '192.0.2.80'); }
    catch (PhoneAuthException $e) { $caught = $e->getMessage(); }
    phone_ok($caught === 'sms_unavailable', "provider {$outcome} surfaced without account enumeration");
    phone_ok($dbx->otps[0]['provider_status'] === $outcome && count($iranx->outbox) === 1, "provider {$outcome} recorded without blind retry");
    $requestId = $dbx->otps[0]['request_id'];
    phone_ok(phone_throws(fn() => $servicex->verify('+989222222222', $requestId, '012345'), 'invalid_or_expired_code'), "provider {$outcome} code cannot verify");
}

// Mock provider idempotency is keyed by request ID.
$mock = new ModasrMockSmsProvider();
$mock->send('+989222222222', 'test', str_repeat('a', 32));
$mock->send('+989222222222', 'test', str_repeat('a', 32));
phone_ok(count($mock->outbox) === 1, 'mock SMS send is idempotent per request ID');

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
