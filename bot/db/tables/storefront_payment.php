<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        order_no VARCHAR(32) NOT NULL,
        provider VARCHAR(32) NOT NULL,
        provider_order_id VARCHAR(64) NULL DEFAULT NULL,
        provider_transid VARCHAR(128) NULL DEFAULT NULL,
        amount_toman BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL,
        error VARCHAR(500) NULL DEFAULT NULL,
        created_at INT UNSIGNED NOT NULL,
        updated_at INT UNSIGNED NOT NULL,
        paid_at INT UNSIGNED NULL DEFAULT NULL,
        UNIQUE KEY uq_storefront_payment_order_provider (order_id, provider),
        UNIQUE KEY uq_storefront_payment_transid (provider, provider_transid),
        KEY ix_storefront_payment_order_no (order_no),
        KEY ix_storefront_payment_status (status)
        SQL,
];
