<?php

declare(strict_types=1);

final class HostingSecretBox
{
    private string $key;

    public function __construct(?string $base64Key = null)
    {
        $encoded = $base64Key ?? (string) getenv('MODASR_FACTORY_KEY');
        $key = base64_decode($encoded, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('factory_key_invalid');
        }
        $this->key = $key;
    }

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('credential_encryption_failed');
        }
        return base64_encode(json_encode([
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'data' => base64_encode($cipher),
        ], JSON_THROW_ON_ERROR));
    }

    public function decrypt(string $encoded): string
    {
        $package = json_decode(base64_decode($encoded, true), true);
        if (!is_array($package)) {
            throw new RuntimeException('credential_package_invalid');
        }
        $iv = base64_decode((string) ($package['iv'] ?? ''), true);
        $tag = base64_decode((string) ($package['tag'] ?? ''), true);
        $cipher = base64_decode((string) ($package['data'] ?? ''), true);
        if ($iv === false || $tag === false || $cipher === false) {
            throw new RuntimeException('credential_package_invalid');
        }
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('credential_decryption_failed');
        }
        return $plain;
    }
}
