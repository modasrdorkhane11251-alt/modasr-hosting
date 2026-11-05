<?php

declare(strict_types=1);

final class HostingAuditLogger
{
    public function __construct(private PDO $pdo)
    {
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function append(
        ?int $actorPrincipalId,
        string $actorRole,
        ?string $tenantKey,
        string $action,
        string $objectType,
        string $objectId,
        ?string $reason = null,
        array $metadata = [],
        ?string $supportSessionId = null,
        ?string $requestId = null
    ): array {
        $requestId = $requestId ?: self::uuid();
        $createdAt = gmdate('Y-m-d H:i:s');
        $metadataJson = json_encode($this->redact($metadata), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $lockName = 'hosting_audit_chain';
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 10)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('audit_lock_failed');
        }
        try {
            $previous = (string) $this->pdo->query('SELECT entry_hash FROM hosting_audit_log ORDER BY id DESC LIMIT 1')->fetchColumn();
            if ($previous === '') {
                $previous = str_repeat('0', 64);
            }
            $entryHash = hash('sha256', implode('|', [
                $previous,
                $requestId,
                (string) $actorPrincipalId,
                $actorRole,
                (string) $tenantKey,
                $action,
                $objectType,
                $objectId,
                (string) $reason,
                $metadataJson,
                $createdAt,
            ]));
            $stmt = $this->pdo->prepare(
                'INSERT INTO hosting_audit_log
                (request_id, actor_principal_id, actor_role, support_session_id, tenant_key, action, object_type, object_id, reason, metadata_json, previous_hash, entry_hash, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $requestId,
                $actorPrincipalId,
                $actorRole,
                $supportSessionId,
                $tenantKey,
                $action,
                $objectType,
                $objectId,
                $reason,
                $metadataJson,
                $previous,
                $entryHash,
                $createdAt,
            ]);
            return ['request_id' => $requestId, 'entry_hash' => $entryHash];
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private function redact(array $data, int $depth = 0): array
    {
        if ($depth > 5) {
            return ['truncated' => true];
        }
        $safe = [];
        foreach ($data as $key => $value) {
            $name = strtolower((string) $key);
            if (preg_match('/(token|secret|password|authorization|cookie|signature|hash|initdata|session|private_key|api_key|apikey|access_key)/', $name)) {
                $safe[$key] = '[redacted]';
                continue;
            }
            if (is_array($value)) {
                $safe[$key] = $this->redact($value, $depth + 1);
            } elseif (is_scalar($value) || $value === null) {
                $safe[$key] = is_string($value) ? mb_substr($value, 0, 512) : $value;
            } else {
                $safe[$key] = '[' . gettype($value) . ']';
            }
        }
        return $safe;
    }
}
