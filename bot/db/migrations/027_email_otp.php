<?php

/** Additive email OTP authentication; never executed by the test suite. */
return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('storefront_email_otp')) {
        $schema->create('storefront_email_otp', <<<SQL
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            request_id CHAR(32) NOT NULL,
            email VARCHAR(254) NOT NULL,
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
            UNIQUE KEY uq_email_otp_request (request_id),
            KEY ix_email_otp_email_created (email, created_at),
            KEY ix_email_otp_ip_created (created_ip_hash, created_at),
            KEY ix_email_otp_expiry (expires_at)
            SQL);
    }

    if (!$schema->tableExists('storefront_email_identity')) {
        $schema->create('storefront_email_identity', <<<SQL
            email VARCHAR(254) NOT NULL PRIMARY KEY,
            user_id VARCHAR(500) NOT NULL,
            created_at INT UNSIGNED NOT NULL,
            UNIQUE KEY uq_email_identity_user (user_id(191))
            SQL);
    }
};
