<?php

/** Signed server-to-server email OTP endpoint; browsers use Express only. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';
require_once __DIR__ . '/../lib/account_link.php';
require_once __DIR__ . '/../lib/email_otp.php';

$data = storefront_signed_payload();
$action = (string) ($data['action'] ?? '');
$clientIp = substr(trim((string) ($data['client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))), 0, 128);

try {
    $hashKey = (string) (getenv('MODASR_PHONE_OTP_HASH_KEY') ?: storefront_secret_value());
    $service = new EmailOtpService($pdo, modasrEmailProviderFromEnvironment(), $hashKey);

    if ($action === 'request') {
        $result = $service->request((string) ($data['email'] ?? ''), $clientIp);
        storefront_json(true, 'code_accepted', $result);
    }
    if ($action === 'verify') {
        $user = $service->verify(
            (string) ($data['email'] ?? ''),
            (string) ($data['request_id'] ?? ''),
            (string) ($data['code'] ?? '')
        );
        storefront_json(true, 'verified', ['user' => $user]);
    }
    storefront_json(false, 'action invalid', [], 400);
} catch (PhoneAuthException $e) {
    storefront_json(false, $e->getMessage(), $e->responseObject(), $e->httpStatus());
} catch (Throwable $e) {
    // Never log email addresses, OTPs, request bodies, or credentials.
    error_log('storefront email auth error: ' . $e::class);
    storefront_json(false, 'server error', [], 500);
}
