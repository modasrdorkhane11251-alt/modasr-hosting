<?php

declare(strict_types=1);

function factoryCatalogPlatforms(): array
{
    return ['telegram' => 'تلگرام', 'rubika' => 'روبیکا', 'soroush' => 'سروش'];
}

function factoryCatalogPackages(): array
{
    return [
        'v:t' => ['vpn', ['telegram']],
        'v:r' => ['vpn', ['rubika']],
        'v:s' => ['vpn', ['soroush']],
        'v:t+r' => ['vpn', ['telegram', 'rubika']],
        'v:t+s' => ['vpn', ['telegram', 'soroush']],
        'v:r+s' => ['vpn', ['rubika', 'soroush']],
        'v:t+r+s' => ['vpn', ['telegram', 'rubika', 'soroush']],
    ];
}

function factoryPlan(string $planCode): array
{
    if ($planCode === 'vpn') {
        $planCode = 'v:t';
    }
    $packages = factoryCatalogPackages();
    if (!isset($packages[$planCode])) {
        throw new RuntimeException('plan_not_found');
    }
    [$product, $platforms] = $packages[$planCode];
    return ['code' => $planCode, 'product' => $product, 'platforms' => $platforms];
}

function factoryPlanPriceToman(PDO $pdo, string $planCode): int
{
    $plan = factoryPlan($planCode);
    $base = factoryPriceToman($pdo);
    if ($base > intdiv(1000000000, count($plan['platforms']))) {
        throw new RuntimeException('order_invalid');
    }
    return $base * count($plan['platforms']);
}

function factoryPlanLabel(string $planCode): string
{
    $plan = factoryPlan($planCode);
    $labels = factoryCatalogPlatforms();
    return implode(' + ', array_map(static fn(string $platform): string => $labels[$platform], $plan['platforms']));
}

function factoryNormalizePlatformToken(string $platform, string $token): string
{
    $token = trim($token);
    $token = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{00A0}\x{200E}\x{200F}\x{202A}-\x{202E}]/u', '', $token) ?? $token;
    $token = str_replace('：', ':', $token);
    return strtr($token, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
}

function factoryPlatformTokenValid(string $platform, string $token): bool
{
    if ($platform === 'telegram') {
        return preg_match('/^\d{6,12}:[A-Za-z0-9_-]{20,}$/', $token) === 1;
    }
    if (in_array($platform, ['rubika', 'soroush'], true)) {
        return preg_match('/^[A-Za-z0-9_:\-]{20,250}$/', $token) === 1;
    }
    return false;
}

function factoryPlatformRequest(string $platform, string $token, string $method, array $params = [], int $timeout = 30): array
{
    if (!in_array($platform, array_keys(factoryCatalogPlatforms()), true) || $token === '') {
        throw new RuntimeException('provider_unauthorized');
    }
    $url = match ($platform) {
        'telegram' => 'https://api.telegram.org/bot' . $token . '/' . $method,
        'soroush' => 'https://api.splus.ir/bot' . $token . '/' . $method,
        'rubika' => 'https://botapi.rubika.ir/v3/' . $token . '/' . $method,
    };
    $isRubika = $platform === 'rubika';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $isRubika ? json_encode($params, JSON_UNESCAPED_UNICODE) : $params,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $isRubika ? ['Content-Type: application/json'] : [],
    ]);
    $raw = curl_exec($ch);
    $error = curl_errno($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($error !== 0 || $raw === false) {
        throw new RuntimeException('provider_unreachable');
    }
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('provider_invalid_response');
    }
    if ($isRubika) {
        if (($decoded['status'] ?? '') !== 'OK') {
            throw new RuntimeException('provider_rejected_' . $status);
        }
        return $decoded['data'] ?? [];
    }
    if (($decoded['ok'] ?? false) !== true) {
        throw new RuntimeException('provider_rejected_' . $status);
    }
    return $decoded['result'] ?? [];
}

function factoryPlatformIdentity(string $platform, string $token): array
{
    $result = factoryPlatformRequest($platform, $token, 'getMe');
    $id = (string) ($result['id'] ?? $result['username'] ?? $result['bot_title'] ?? '');
    $username = (string) ($result['username'] ?? $result['bot_title'] ?? $result['first_name'] ?? '');
    if ($id === '' || $username === '') {
        throw new RuntimeException('provider_rejected');
    }
    return ['id' => $id, 'username' => $username];
}

function factoryNextTokenPlatform(array $plan, array $bundle): ?string
{
    $tokens = is_array($bundle['tokens'] ?? null) ? $bundle['tokens'] : [];
    foreach ($plan['platforms'] as $platform) {
        if (!isset($tokens[$platform]) || !is_string($tokens[$platform]) || $tokens[$platform] === '') {
            return $platform;
        }
    }
    return null;
}
