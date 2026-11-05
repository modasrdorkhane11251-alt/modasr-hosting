<?php

declare(strict_types=1);

final class HostingInstanceRepository
{
    private const TRANSITIONS = [
        'pending_credentials' => ['ready'],
        'ready' => ['provisioning'],
        'provisioning' => ['stopped', 'failed'],
        'stopped' => ['active'],
        'active' => ['stopped', 'failed'],
        'failed' => ['provisioning', 'stopped'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function get(string $instanceId, string $tenantKey): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hosting_bot_instance WHERE id = ? AND tenant_key = ?');
        $stmt->execute([$instanceId, $tenantKey]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('instance_not_found');
        }
        return $row;
    }

    public function transition(string $instanceId, string $tenantKey, string $target, int $actorPrincipalId, HostingAuditLogger $audit): void
    {
        $instance = $this->get($instanceId, $tenantKey);
        $current = (string) $instance['runtime_status'];
        if (!in_array($target, self::TRANSITIONS[$current] ?? [], true)) {
            throw new RuntimeException('instance_transition_invalid');
        }
        $stmt = $this->pdo->prepare('UPDATE hosting_bot_instance SET runtime_status = ? WHERE id = ? AND tenant_key = ? AND runtime_status = ?');
        $stmt->execute([$target, $instanceId, $tenantKey, $current]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('instance_transition_conflict');
        }
        $audit->append($actorPrincipalId, 'PLATFORM_OWNER', $tenantKey, 'instance.' . strtolower($target), 'bot_instance', $instanceId, null, ['from' => $current, 'to' => $target]);
    }
}
