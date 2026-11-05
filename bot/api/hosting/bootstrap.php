<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/hosting/SecretBox.php';
require_once __DIR__ . '/../../lib/hosting/AuditLogger.php';
require_once __DIR__ . '/../../lib/hosting/Rbac.php';
require_once __DIR__ . '/../../lib/hosting/SupportService.php';
require_once __DIR__ . '/../../lib/hosting/DatabaseProvisioner.php';
require_once __DIR__ . '/../../lib/hosting/InstanceRepository.php';

function hostingApplyCoreMigrations(PDO $pdo, string $botRoot): void
{
    foreach (['001_core', '002_factory_constraints'] as $name) {
        $migration = require $botRoot . '/db/migrations/hosting/' . $name . '.php';
        $migration($pdo);
    }
}
