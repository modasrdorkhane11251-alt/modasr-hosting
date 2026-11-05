<?php

declare(strict_types=1);

return static function (PDO $pdo): void {
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hosting_web_magic_token (
            id CHAR(36) NOT NULL PRIMARY KEY,
            tenant_key CHAR(24) NOT NULL,
            principal_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(32) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            expires_at BIGINT NOT NULL,
            used_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_web_magic_tenant (tenant_key, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS hosting_web_session (
            id CHAR(36) NOT NULL PRIMARY KEY,
            tenant_key CHAR(24) NOT NULL,
            principal_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(32) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            csrf_hash CHAR(64) NOT NULL,
            ip_hash CHAR(64) NOT NULL,
            user_agent_hash CHAR(64) NOT NULL,
            last_seen_at BIGINT NOT NULL,
            absolute_expires_at BIGINT NOT NULL,
            revoked_at TIMESTAMP NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_web_session_tenant (tenant_key, last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
};
