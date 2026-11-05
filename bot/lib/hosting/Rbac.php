<?php

declare(strict_types=1);

final class HostingRbac
{
    private const ROLES = [
        'PLATFORM_OWNER',
        'SUPPORT_OPERATOR',
        'TENANT_OWNER',
        'TENANT_ADMIN',
        'TENANT_STAFF',
    ];

    private const PERMISSIONS = [
        'PLATFORM_OWNER' => ['*'],
        'SUPPORT_OPERATOR' => ['tenant.read', 'instances.read', 'instances.restart', 'orders.read', 'support.read'],
        'TENANT_OWNER' => [
            'tenant.read', 'tenant.manage', 'instances.read', 'instances.start',
            'instances.stop', 'instances.restart', 'orders.read', 'orders.create',
            'credentials.rotate', 'support.read', 'reports.read',
        ],
        'TENANT_ADMIN' => [
            'tenant.read', 'instances.read', 'instances.start', 'instances.stop',
            'instances.restart', 'orders.read', 'orders.create', 'support.read', 'reports.read',
        ],
        'TENANT_STAFF' => ['tenant.read', 'instances.read', 'reports.read'],
    ];

    public function __construct(private PDO $pdo)
    {
    }

    public function principal(string $platform, string $platformUserId): array
    {
        $this->assertPlatform($platform);
        if ($platformUserId === '' || strlen($platformUserId) > 128) {
            throw new RuntimeException('principal_invalid');
        }
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO hosting_principal (platform, platform_user_id) VALUES (?, ?)');
        $stmt->execute([$platform, $platformUserId]);
        $stmt = $this->pdo->prepare('SELECT * FROM hosting_principal WHERE platform = ? AND platform_user_id = ?');
        $stmt->execute([$platform, $platformUserId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('principal_invalid');
        }
        return $row;
    }

    public function assignRole(int $principalId, string $role, string $tenantKey = ''): void
    {
        if (!in_array($role, self::ROLES, true) || ($tenantKey !== '' && !preg_match('/^[a-f0-9]{24}$/', $tenantKey))) {
            throw new RuntimeException('role_assignment_invalid');
        }
        if ($tenantKey === '' && !in_array($role, ['PLATFORM_OWNER', 'SUPPORT_OPERATOR'], true)) {
            throw new RuntimeException('global_role_denied');
        }
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO hosting_role_assignment (principal_id, role, tenant_key) VALUES (?, ?, ?)');
        $stmt->execute([$principalId, $role, $tenantKey]);
    }

    public function bootstrapOwnerAliases(): void
    {
        foreach (['telegram', 'bale', 'rubika', 'soroush'] as $platform) {
            $envName = 'MODASR_OWNER_' . strtoupper($platform) . '_ID';
            $ownerId = (string) getenv($envName);
            if ($ownerId === '') {
                continue;
            }
            $principal = $this->principal($platform, $ownerId);
            $this->assignRole((int) $principal['id'], 'PLATFORM_OWNER');
        }
    }

    public function hasPermission(int $principalId, string $permission, string $tenantKey): bool
    {
        $stmt = $this->pdo->prepare('SELECT role, tenant_key FROM hosting_role_assignment WHERE principal_id = ? AND (tenant_key = "" OR tenant_key = ?)');
        $stmt->execute([$principalId, $tenantKey]);
        foreach ($stmt->fetchAll() as $assignment) {
            $role = (string) $assignment['role'];
            $permissions = self::PERMISSIONS[$role] ?? [];
            if (in_array('*', $permissions, true) || in_array($permission, $permissions, true)) {
                return true;
            }
        }
        return false;
    }

    public function requirePermission(int $principalId, string $permission, string $tenantKey): void
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $tenantKey) || !$this->hasPermission($principalId, $permission, $tenantKey)) {
            throw new RuntimeException('access_denied');
        }
    }

    private function assertPlatform(string $platform): void
    {
        if (!in_array($platform, ['telegram', 'bale', 'rubika', 'soroush'], true)) {
            throw new RuntimeException('platform_not_supported');
        }
    }
}
