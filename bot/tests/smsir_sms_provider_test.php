<?php

/** SMS.ir adapter contract tests; all HTTP responses are synthetic. */
require_once __DIR__ . '/../lib/smsir_sms_provider.php';

$passed = 0; $failed = 0;
function smsir_ok(bool $ok, string $label): void { global $passed, $failed; $ok ? $passed++ : $failed++; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $label . "\n"; }

$captured = null;
$http = static function (string $url, array $payload, array $headers, int $timeout) use (&$captured): array {
    $captured = ['url' => $url, 'payload' => $payload, 'headers' => $headers, 'timeout' => $timeout];
    return ['http' => 200, 'json' => ['status' => 1, 'message' => 'success', 'data' => ['messageId' => 123456, 'cost' => 10]], 'transport_ok' => true];
};
$provider = new ModasrSmsIrProvider('test-only-key', 707965, 'Code', 'https://api.sms.ir', 10, $http);
$result = $provider->send('+989121234567', 'MODASR login code: 012345. It expires in 5 minutes.', str_repeat('a', 32));
smsir_ok($result['status'] === 'sent' && $result['message_id'] === '123456', 'successful SMS.ir response normalized');
smsir_ok($captured['url'] === 'https://api.sms.ir/v1/send/verify', 'documented verify endpoint used');
smsir_ok($captured['payload']['mobile'] === '+989121234567', 'Iranian E.164 destination mapped');
smsir_ok($captured['payload']['templateId'] === 707965, 'template ID mapped as integer');
smsir_ok($captured['payload']['parameters'] === [['name' => 'Code', 'value' => '012345']], 'OTP parameter name/value mapped');
smsir_ok($captured['payload']['sendDateTime'] ?? null === null, 'no scheduled send field added');
smsir_ok(count(array_filter($captured['headers'], fn($h) => str_starts_with($h, 'x-api-key: '))) === 1, 'API key exists only in server-side header');
smsir_ok($captured['timeout'] === 10, 'provider timeout is bounded');

$calls = 0;
$noNetwork = static function () use (&$calls): array { $calls++; return ['http' => 200, 'json' => null, 'transport_ok' => true]; };
$provider2 = new ModasrSmsIrProvider('test-only-key', 707965, 'Code', 'https://api.sms.ir', 10, $noNetwork);
try { $provider2->send('+14155550123', 'MODASR login code: 012345.', str_repeat('b', 32)); smsir_ok(false, 'international destination rejected'); }
catch (InvalidArgumentException) { smsir_ok(true, 'international destination rejected'); }
smsir_ok($calls === 0, 'international rejection occurs before HTTP');

$rejected = new ModasrSmsIrProvider('test-only-key', 707965, 'Code', 'https://api.sms.ir', 10, static fn(): array => ['http' => 200, 'json' => ['status' => 10, 'message' => 'wrong key'], 'transport_ok' => true]);
smsir_ok($rejected->send('+989121234567', 'MODASR login code: 012345.', str_repeat('c', 32))['status'] === 'failed', 'definitive provider rejection becomes failed');
$transport = new ModasrSmsIrProvider('test-only-key', 707965, 'Code', 'https://api.sms.ir', 10, static fn(): array => ['http' => 0, 'json' => null, 'transport_ok' => false]);
smsir_ok($transport->send('+989121234567', 'MODASR login code: 012345.', str_repeat('d', 32))['status'] === 'unknown', 'transport failure becomes unknown');
$server = new ModasrSmsIrProvider('test-only-key', 707965, 'Code', 'https://api.sms.ir', 10, static fn(): array => ['http' => 500, 'json' => null, 'transport_ok' => true]);
smsir_ok($server->send('+989121234567', 'MODASR login code: 012345.', str_repeat('e', 32))['status'] === 'unknown', 'provider server error becomes unknown');
$malformed = new ModasrSmsIrProvider('test-only-key', 707965, 'Code', 'https://api.sms.ir', 10, static fn(): array => ['http' => 200, 'json' => null, 'transport_ok' => true]);
smsir_ok($malformed->send('+989121234567', 'MODASR login code: 012345.', str_repeat('f', 32))['status'] === 'unknown', 'malformed success response becomes unknown');

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
