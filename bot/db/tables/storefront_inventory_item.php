<?php

return [
    'create' => <<<SQL
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL,
        secret_ciphertext TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'available',
        order_id INT UNSIGNED NULL DEFAULT NULL,
        reserved_at INT UNSIGNED NULL DEFAULT NULL,
        delivered_at INT UNSIGNED NULL DEFAULT NULL,
        created_at INT UNSIGNED NOT NULL,
        updated_at INT UNSIGNED NOT NULL,
        KEY ix_inventory_product_status (product_id, status),
        KEY ix_inventory_order (order_id)
        SQL,
];
