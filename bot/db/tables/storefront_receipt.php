<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        order_no VARCHAR(32) NOT NULL,
        id_user VARCHAR(500) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        mime VARCHAR(40) NOT NULL,
        sha256 VARCHAR(64) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'waiting',
        reviewer VARCHAR(200) NULL DEFAULT NULL,
        review_note TEXT NULL,
        created_at INT UNSIGNED NOT NULL,
        reviewed_at INT UNSIGNED NULL DEFAULT NULL,
        KEY ix_receipt_order (order_id),
        KEY ix_receipt_status (status),
        KEY ix_receipt_user (id_user)
        SQL,
];
