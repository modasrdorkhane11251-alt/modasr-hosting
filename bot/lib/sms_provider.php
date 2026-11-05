<?php

/**
 * Provider-independent SMS boundary.
 *
 * This phase intentionally contains only an in-memory mock. It has no HTTP
 * client and cannot send a real SMS. Production providers can implement the
 * same interface without changing the OTP service or browser contract.
 */
interface ModasrSmsProvider
{
    public function name(): string;

    /**
     * @return array{status:string,message_id:?string}
     * status: sent, failed, or unknown. Unknown must not be blindly retried.
     */
    public function send(string $phoneE164, string $message, string $requestId): array;
}

final class ModasrMockSmsProvider implements ModasrSmsProvider
{
    /** @var array<int,array{phone:string,message:string,request_id:string}> */
    public array $outbox = [];

    public function __construct(
        private string $providerName = 'mock',
        private string $outcome = 'sent'
    ) {
        if (!in_array($this->outcome, ['sent', 'failed', 'unknown'], true)) {
            throw new InvalidArgumentException('invalid mock outcome');
        }
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function send(string $phoneE164, string $message, string $requestId): array
    {
        if (!preg_match('/^\+[1-9]\d{7,14}$/', $phoneE164)) {
            throw new InvalidArgumentException('invalid destination');
        }
        if (!preg_match('/^[a-f0-9]{32}$/', $requestId) || $message === '') {
            throw new InvalidArgumentException('invalid mock request');
        }

        // Idempotent by OTP request ID: a repeated call does not add another
        // outbox message or provider-side delivery attempt.
        foreach ($this->outbox as $entry) {
            if ($entry['request_id'] === $requestId) {
                return ['status' => $this->outcome, 'message_id' => 'mock_' . $requestId];
            }
        }

        $this->outbox[] = ['phone' => $phoneE164, 'message' => $message, 'request_id' => $requestId];
        return ['status' => $this->outcome, 'message_id' => 'mock_' . $requestId];
    }
}

final class ModasrSmsRouter
{
    public function __construct(
        private ModasrSmsProvider $iranian,
        private ModasrSmsProvider $international
    ) {}

    public function providerFor(string $phoneE164): ModasrSmsProvider
    {
        return str_starts_with($phoneE164, '+98') ? $this->iranian : $this->international;
    }
}

final class ModasrDisabledInternationalSmsProvider implements ModasrSmsProvider
{
    public function name(): string
    {
        return 'disabled_international';
    }

    public function send(string $phoneE164, string $message, string $requestId): array
    {
        throw new RuntimeException('foreign_email_required');
    }
}

function modasrSmsRouterFromEnvironment(): ModasrSmsRouter
{
    $iranian = strtolower(trim((string) (getenv('MODASR_SMS_IRAN_PROVIDER') ?: 'mock')));
    if ($iranian === 'mock') {
        $provider = new ModasrMockSmsProvider('mock_iran');
    } elseif ($iranian === 'ghasedak') {
        require_once __DIR__ . '/ghasedak_sms_provider.php';
        $provider = new ModasrGhasedakSmsProvider(
            trim((string) (getenv('MODASR_GHASEDAK_API_KEY') ?: '')),
            trim((string) (getenv('MODASR_GHASEDAK_OTP_TEMPLATE') ?: '')),
            trim((string) (getenv('MODASR_GHASEDAK_API_BASE') ?: 'https://gateway.ghasedak.me'))
        );
    } elseif ($iranian === 'smsir') {
        require_once __DIR__ . '/smsir_sms_provider.php';
        $provider = new ModasrSmsIrProvider(
            trim((string) (getenv('MODASR_SMSIR_API_KEY') ?: '')),
            (int) (getenv('MODASR_SMSIR_TEMPLATE_ID') ?: 0),
            trim((string) (getenv('MODASR_SMSIR_PARAMETER_NAME') ?: 'CODE')),
            trim((string) (getenv('MODASR_SMSIR_API_BASE') ?: 'https://api.sms.ir'))
        );
    } else {
        throw new RuntimeException('sms_provider_not_configured');
    }

    // International SMS is disabled by product decision. The old international
    // environment variable is intentionally ignored; foreign users use email.
    return new ModasrSmsRouter($provider, new ModasrDisabledInternationalSmsProvider());
}

// Backward-compatible name for the isolated phase-one test harness.
function modasrMockSmsRouterFromEnvironment(): ModasrSmsRouter
{
    return modasrSmsRouterFromEnvironment();
}
