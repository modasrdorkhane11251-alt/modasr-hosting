<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $tables = [
        'hosting_tenant' => "CREATE TABLE IF NOT EXISTS hosting_tenant (
            tenant_key CHAR(24) NOT NULL PRIMARY KEY,
            owner_id VARCHAR(64) NOT NULL,
            db_name VARCHAR(64) NOT NULL UNIQUE,
            db_user VARCHAR(32) NOT NULL UNIQUE,
            schema_version INT UNSIGNED NOT NULL DEFAULT 1,
            status VARCHAR(24) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_hosting_tenant_owner (owner_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_principal' => "CREATE TABLE IF NOT EXISTS hosting_principal (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            platform VARCHAR(16) NOT NULL,
            platform_user_id VARCHAR(128) NOT NULL,
            display_name VARCHAR(190) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hosting_principal (platform, platform_user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_role_assignment' => "CREATE TABLE IF NOT EXISTS hosting_role_assignment (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            principal_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(32) NOT NULL,
            tenant_key CHAR(24) NOT NULL DEFAULT '',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hosting_role (principal_id, role, tenant_key),
            KEY idx_hosting_role_tenant (tenant_key, role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_credential' => "CREATE TABLE IF NOT EXISTS hosting_credential (
            id CHAR(36) NOT NULL PRIMARY KEY,
            tenant_key CHAR(24) NOT NULL,
            instance_id CHAR(36) NULL,
            purpose VARCHAR(32) NOT NULL,
            ciphertext LONGTEXT NOT NULL,
            key_version INT UNSIGNED NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            rotated_at TIMESTAMP NULL,
            revoked_at TIMESTAMP NULL,
            KEY idx_hosting_credential_tenant (tenant_key, purpose, revoked_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_bot_instance' => "CREATE TABLE IF NOT EXISTS hosting_bot_instance (
            id CHAR(36) NOT NULL PRIMARY KEY,
            tenant_key CHAR(24) NOT NULL,
            product_code VARCHAR(32) NOT NULL,
            platform VARCHAR(16) NOT NULL,
            credential_id CHAR(36) NULL,
            external_bot_id_hash CHAR(64) NOT NULL,
            username_snapshot VARCHAR(190) NOT NULL,
            runtime_kind VARCHAR(24) NOT NULL,
            runtime_status VARCHAR(24) NOT NULL,
            service_name VARCHAR(96) NOT NULL UNIQUE,
            adapter_version VARCHAR(32) NOT NULL,
            product_version VARCHAR(32) NOT NULL,
            last_health_at TIMESTAMP NULL,
            last_error_code VARCHAR(96) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hosting_instance_tenant (tenant_key, product_code, platform),
            UNIQUE KEY uq_hosting_instance_external (platform, external_bot_id_hash),
            KEY idx_hosting_instance_tenant_status (tenant_key, runtime_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_order' => "CREATE TABLE IF NOT EXISTS hosting_order (
            id CHAR(36) NOT NULL PRIMARY KEY,
            order_number VARCHAR(48) NOT NULL UNIQUE,
            tenant_key CHAR(24) NOT NULL,
            plan_code VARCHAR(32) NOT NULL,
            amount_toman BIGINT NOT NULL,
            currency VARCHAR(12) NOT NULL DEFAULT 'toman',
            payment_status VARCHAR(24) NOT NULL,
            fulfillment_status VARCHAR(24) NOT NULL,
            source_platform VARCHAR(16) NOT NULL,
            idempotency_key CHAR(64) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_hosting_order_tenant (tenant_key, payment_status, fulfillment_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_subscription' => "CREATE TABLE IF NOT EXISTS hosting_subscription (
            id CHAR(36) NOT NULL PRIMARY KEY,
            tenant_key CHAR(24) NOT NULL,
            order_id CHAR(36) NOT NULL,
            status VARCHAR(24) NOT NULL,
            starts_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hosting_subscription_order (order_id),
            KEY idx_hosting_subscription_expiry (tenant_key, status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_support_session' => "CREATE TABLE IF NOT EXISTS hosting_support_session (
            id CHAR(36) NOT NULL PRIMARY KEY,
            tenant_key CHAR(24) NOT NULL,
            operator_principal_id BIGINT UNSIGNED NOT NULL,
            reason VARCHAR(500) NOT NULL,
            permissions_json TEXT NOT NULL,
            status VARCHAR(24) NOT NULL,
            approved_by BIGINT UNSIGNED NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            closed_at TIMESTAMP NULL,
            KEY idx_hosting_support_tenant (tenant_key, status, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_audit_log' => "CREATE TABLE IF NOT EXISTS hosting_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            request_id CHAR(36) NOT NULL,
            actor_principal_id BIGINT UNSIGNED NULL,
            actor_role VARCHAR(32) NOT NULL,
            support_session_id CHAR(36) NULL,
            tenant_key CHAR(24) NULL,
            action VARCHAR(96) NOT NULL,
            object_type VARCHAR(48) NOT NULL,
            object_id VARCHAR(64) NOT NULL,
            reason VARCHAR(500) NULL,
            metadata_json TEXT NOT NULL,
            previous_hash CHAR(64) NOT NULL,
            entry_hash CHAR(64) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_hosting_audit_tenant (tenant_key, created_at),
            KEY idx_hosting_audit_support (support_session_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_job' => "CREATE TABLE IF NOT EXISTS hosting_job (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_key CHAR(24) NULL,
            job_type VARCHAR(48) NOT NULL,
            idempotency_key CHAR(64) NOT NULL UNIQUE,
            status VARCHAR(24) NOT NULL,
            run_after BIGINT NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            payload_json TEXT NOT NULL,
            last_error VARCHAR(190) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_hosting_job_queue (status, run_after)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
        'hosting_schema_migration' => "CREATE TABLE IF NOT EXISTS hosting_schema_migration (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            tenant_key CHAR(24) NOT NULL,
            migration_name VARCHAR(190) NOT NULL,
            status VARCHAR(24) NOT NULL,
            started_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            finished_at TIMESTAMP NULL,
            error VARCHAR(190) NULL,
            UNIQUE KEY uq_hosting_tenant_migration (tenant_key, migration_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci",
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }
};
