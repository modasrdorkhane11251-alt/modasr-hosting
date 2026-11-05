<?php

require_once __DIR__ . '/sms_provider.php';

/** Ghasedak transactional OTP adapter. No sender line is required by OTP API. */
final class ModasrGhasedakSmsProvider implements ModasrSmsProvider
{
    private Closure $httpClient;

    public function __construct(
        private string $apiKey,
        private string $templateName,
        private string $apiBase = 'https://gateway.ghasedak.me',
        private int $timeoutSeconds = 10,
        ?callable $httpClient = null
    ) {
        if ($this->apiKey === '' || $this->templateName === '' || $this->timeoutSeconds < 1 || $this->timeoutSeconds > 30) {
            throw new InvalidArgumentException('ghasedak_not_configured');
        }
        if (!preg_match('#^https://#', $this->apiBase)) {
            throw new InvalidArgumentException('ghasedak_api_base_invalid');
        }
        $this->apiBase = rtrim($this->apiBase, '/');
        $this->httpClient = $httpClient !== null
            ? Closure::fromCallable($httpClient)
            : static fn(string $url, array $payload, array $headers, int $timeout): array => modasrGhasedakPostJson($url, $payload, $headers, $timeout);
    }

    public function name(): string
    {
        return 'ghasedak';
    }

    public function send(string $phoneE164, string $message, string $requestId): array
    {
        if (!preg_match('/^\+989\d{9}$/', $phoneE164) || !preg_match('/^[a-f0-9]{32}$/', $requestId)) {
            throw new InvalidArgumentException('ghasedak_destination_invalid');
        }
        if (!preg_match('/\b(\d{6})\b/', $message, $matches)) {
            throw new InvalidArgumentException('ghasedak_otp_missing');
        }

        $payload = [
            'receptors' => [[
                'mobile' => $phoneE164,
                'clientReferenceId' => $requestId,
            ]],
            'templateName' => $this->templateName,
            'param1' => $matches[1],
            'isVoice' => false,
        ];
        $headers = ['apikey: ' . $this->apiKey, 'Content-Type: application/json'];

        try {
            $response = ($this->httpClient)(
                $this->apiBase . '/rest/api/v1/webservice/sendotpwithparams',
                $payload,
                $headers,
                $this->timeoutSeconds
            );
        } catch (Throwable) {
            return ['status' => 'unknown', 'message_id' => null];
        }

        $http = (int) ($response['http'] ?? 0);
        $json = $response['json'] ?? null;
        if (($response['transport_ok'] ?? false) !== true || $http === 0 || $http >= 500) {
            return ['status' => 'unknown', 'message_id' => null];
        }
        if ($http >= 200 && $http < 300 && is_array($json) && ($json['isSuccess'] ?? false) === true) {
            $messageId = $json['data']['messageId'] ?? $json['data']['id'] ?? null;
            return ['status' => 'sent', 'message_id' => is_scalar($messageId) ? substr((string) $messageId, 0, 128) : null];
        }
        if ($http >= 400 && $http < 500) {
            return ['status' => 'failed', 'message_id' => null];
        }
        return ['status' => 'unknown', 'message_id' => null];
    }
}

/** Default network transport, called only when the real provider is selected. */
function modasrGhasedakPostJson(string $url, array $payload, array $headers, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $transportOk = $raw !== false;
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = $transportOk ? json_decode((string) $raw, true) : null;
    return ['http' => $http, 'json' => is_array($json) ? $json : null, 'transport_ok' => $transportOk];
}
