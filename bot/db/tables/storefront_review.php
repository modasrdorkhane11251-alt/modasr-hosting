<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        order_id INT UNSIGNED NOT NULL,
        product_code VARCHAR(120) NOT NULL,
        id_user VARCHAR(500) NOT NULL,
        rating TINYINT UNSIGNED NOT NULL,
        review_text TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at INT UNSIGNED NOT NULL,
        reviewed_at INT UNSIGNED NULL DEFAULT NULL,
        UNIQUE KEY uq_review_order_product (order_id, product_code),
        KEY ix_review_product_status (product_code, status)
        SQL,
];
