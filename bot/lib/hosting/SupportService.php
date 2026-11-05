<?php

declare(strict_types=1);

final class HostingSupportService
{
    public function __construct(
        private PDO $pdo,
        private HostingRbac $rbac,
        private HostingAuditLogger $audit
    ) {
    }

    public function open(int $operatorPrincipalId, string $tenantKey, string $reason, array $permissions = ['support.read'], int $ttlSeconds = 3600): array
    {
        $reason = trim($reason);
        if (strlen($reason) < 5 || strlen($reason) > 500 || $ttlSeconds < 60 || $ttlSeconds > 14400) {
            throw new RuntimeException('support_request_invalid');
        }
        $allowed = ['support.read', 'tenant.read', 'instances.read', 'instances.restart', 'orders.read'];
        $permissions = array_values(array_intersect($permissions, $allowed));
        if ($permissions === []) {
            throw new RuntimeException('support_permissions_invalid');
        }

        $isOwner = $this->rbac->hasPermission($operatorPrincipalId, '*', $tenantKey);
        $isSupport = $this->rbac->hasPermission($operatorPrincipalId, 'support.read', $tenantKey);
        if (!$isOwner && !$isSupport) {
            throw new RuntimeException('access_denied');
        }
        $status = $isOwner ? 'active' : 'pending';
        $id = HostingAuditLogger::uuid();
        $expiresAt = time() + $ttlSeconds;
        $stmt = $this->pdo->prepare(
            'INSERT INTO hosting_support_session (id, tenant_key, operator_principal_id, reason, permissions_json, status, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $id,
            $tenantKey,
            $operatorPrincipalId,
            $reason,
            json_encode(array_values($permissions), JSON_THROW_ON_ERROR),
            $status,
            gmdate('Y-m-d H:i:s', $expiresAt),
        ]);
        $this->audit->append($operatorPrincipalId, $isOwner ? 'PLATFORM_OWNER' : 'SUPPORT_OPERATOR', $tenantKey, 'support.open', 'support_session', $id, $reason, ['status' => $status], $id);
        return ['id' => $id, 'status' => $status, 'expires_at' => $expiresAt];
    }

    public function approve(int $ownerPrincipalId, string $sessionId, string $tenantKey): void
    {
        $this->rbac->requirePermission($ownerPrincipalId, '*', $tenantKey);
        $stmt = $this->pdo->prepare('UPDATE hosting_support_session SET status = "active", approved_by = ? WHERE id = ? AND tenant_key = ? AND status = "pending" AND expires_at > CURRENT_TIMESTAMP');
        $stmt->execute([$ownerPrincipalId, $sessionId, $tenantKey]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('support_approval_invalid');
        }
        $this->audit->append($ownerPrincipalId, 'PLATFORM_OWNER', $tenantKey, 'support.approve', 'support_session', $sessionId, null, [], $sessionId);
    }

    public function authorize(int $principalId, string $sessionId, string $tenantKey, string $permission): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM hosting_support_session WHERE id = ? AND tenant_key = ? AND status = "active" AND expires_at > CURRENT_TIMESTAMP');
        $stmt->execute([$sessionId, $tenantKey]);
        $session = $stmt->fetch();
        if (!is_array($session) || (int) $session['operator_principal_id'] !== $principalId) {
            throw new RuntimeException('access_denied');
        }
        $permissions = json_decode((string) $session['permissions_json'], true);
        if (!is_array($permissions) || !in_array($permission, $permissions, true)) {
            throw new RuntimeException('access_denied');
        }
        $this->rbac->requirePermission($principalId, $permission, $tenantKey);
        $this->audit->append($principalId, 'SUPPORT_OPERATOR', $tenantKey, 'support.use', 'support_session', $sessionId, null, ['permission' => $permission], $sessionId);
        return $session;
    }

    public function close(int $principalId, string $sessionId, string $tenantKey): void
    {
        $stmt = $this->pdo->prepare('UPDATE hosting_support_session SET status = "closed", closed_at = CURRENT_TIMESTAMP WHERE id = ? AND tenant_key = ? AND operator_principal_id = ? AND status IN ("pending", "active")');
        $stmt->execute([$sessionId, $tenantKey, $principalId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('support_close_invalid');
        }
        $this->audit->append($principalId, 'SUPPORT_OPERATOR', $tenantKey, 'support.close', 'support_session', $sessionId, null, [], $sessionId);
    }
}
