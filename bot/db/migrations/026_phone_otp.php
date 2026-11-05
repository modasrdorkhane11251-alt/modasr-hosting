<?php

/**
 * Additive phone OTP authentication schema.
 *
 * The only existing-table addition is the nullable user.platform column used
 * by phone/email identities. No existing rows are rewritten.
 * This migration is NOT executed by the development test suite.
 */
return static function (PDO $pdo, Schema $schema): void {
    $userColumns = $pdo->query('SHOW COLUMNS FROM `user`')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('platform', $userColumns, true)) {
        $pdo->exec("ALTER TABLE `user` ADD COLUMN `platform` VARCHAR(50) NULL DEFAULT NULL AFTER `id`");
    }

    if (!$schema->tableExists('storefront_phone_otp')) {
        $schema->create('storefront_phone_otp', <<<SQL
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id CHAR(32) NOT NULL,
            phone_e164 VARCHAR(16) NOT NULL,
            code_hash CHAR(64) NOT NULL,
            expires_at INT UNSIGNED NOT NULL,
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            resend_after INT UNSIGNED NOT NULL,
            provider VARCHAR(40) NOT NULL,
            provider_message_id VARCHAR(128) NULL DEFAULT NULL,
            provider_status VARCHAR(20) NOT NULL DEFAULT 'pending',
            created_ip_hash CHAR(64) NOT NULL,
            created_at INT UNSIGNED NOT NULL,
            consumed_at INT UNSIGNED NULL DEFAULT NULL,
            superseded_at INT UNSIGNED NULL DEFAULT NULL,
            UNIQUE KEY uq_phone_otp_request (request_id),
            KEY ix_phone_otp_phone_created (phone_e164, created_at),
            KEY ix_phone_otp_ip_created (created_ip_hash, created_at),
            KEY ix_phone_otp_expiry (expires_at)
            SQL);
    }

    if (!$schema->tableExists('storefront_phone_identity')) {
        $schema->create('storefront_phone_identity', <<<SQL
            phone_e164 VARCHAR(16) NOT NULL PRIMARY KEY,
            user_id VARCHAR(500) NOT NULL,
            created_at INT UNSIGNED NOT NULL,
            UNIQUE KEY uq_phone_identity_user (user_id(191))
            SQL);
    }
};
