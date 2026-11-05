<?php

/**
 * Signed server-to-server phone OTP endpoint.
 * Browser clients use the same-origin Express routes, never this endpoint
 * directly. Only Iranian +98 destinations are accepted; foreign users use email.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/storefront_http.php';
require_once __DIR__ . '/../lib/account_link.php';
require_once __DIR__ . '/../lib/phone_otp.php';

$data = storefront_signed_payload();
$action = (string) ($data['action'] ?? '');
$clientIp = substr(trim((string) ($data['client_ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown'))), 0, 128);

try {
    $hashKey = (string) (getenv('MODASR_PHONE_OTP_HASH_KEY') ?: storefront_secret_value());
    $service = new PhoneOtpService($pdo, modasrSmsRouterFromEnvironment(), $hashKey);

    if ($action === 'request') {
        $result = $service->request((string) ($data['phone'] ?? ''), $clientIp);
        storefront_json(true, 'code_accepted', $result);
    }

    if ($action === 'verify') {
        $user = $service->verify(
            (string) ($data['phone'] ?? ''),
            (string) ($data['request_id'] ?? ''),
            (string) ($data['code'] ?? '')
        );
        storefront_json(true, 'verified', ['user' => $user]);
    }

    storefront_json(false, 'action invalid', [], 400);
} catch (PhoneAuthException $e) {
    storefront_json(false, $e->getMessage(), $e->responseObject(), $e->httpStatus());
} catch (Throwable $e) {
    // Do not log phone numbers, OTPs, request bodies, credentials, or SQL data.
    error_log('storefront phone auth error: ' . $e::class);
    storefront_json(false, 'server error', [], 500);
}
