<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        id_user VARCHAR(500) NOT NULL,
        reason TEXT NOT NULL,
        amount_toman BIGINT UNSIGNED NOT NULL,
        status VARCHAR(24) NOT NULL DEFAULT 'requested',
        reviewer VARCHAR(200) NULL DEFAULT NULL,
        review_note TEXT NULL,
        provider_ref VARCHAR(128) NULL DEFAULT NULL,
        created_at INT UNSIGNED NOT NULL,
        reviewed_at INT UNSIGNED NULL DEFAULT NULL,
        UNIQUE KEY uq_refund_order (order_id),
        KEY ix_refund_status (status)
        SQL,
];
