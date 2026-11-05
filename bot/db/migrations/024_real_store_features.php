<?php

/**
 * Migration 024 — real storefront features.
 * Additive fields/tables only; no existing order, product, payment or user
 * row is deleted or rewritten.
 */
return static function (PDO $pdo, Schema $schema): void {
    $addColumn = static function (string $table, string $column, string $definition) use ($schema, $pdo): void {
        if (!$schema->hasColumn($table, $column)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    };

    $addColumn('product', 'multi_month', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `storefront_image`");
    $addColumn('product', 'inventory_enabled', "TINYINT(1) NOT NULL DEFAULT 0 AFTER `multi_month`");
    $addColumn('product', 'tax_included', "TINYINT(1) NOT NULL DEFAULT 1 AFTER `inventory_enabled`");
    $addColumn('setting', 'storefront_tax_bps', "INT UNSIGNED NOT NULL DEFAULT 500 AFTER `webhook_secret`");
    $addColumn('support_message', 'order_no', "VARCHAR(32) NULL DEFAULT NULL AFTER `status`");
    $addColumn('DiscountSell', 'valid_from', "INT UNSIGNED NULL DEFAULT NULL AFTER `usedDiscount`");
    $addColumn('DiscountSell', 'valid_to', "INT UNSIGNED NULL DEFAULT NULL AFTER `valid_from`");
    $addColumn('DiscountSell', 'per_user_limit', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `valid_to`");

    if (!$schema->tableExists('storefront_inventory_item')) {
        $schema->create('storefront_inventory_item', <<<SQL
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
            SQL);
    }

    if (!$schema->tableExists('storefront_refund')) {
        $schema->create('storefront_refund', <<<SQL
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
            SQL);
    }

    if (!$schema->tableExists('storefront_review')) {
        $schema->create('storefront_review', <<<SQL
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
            SQL);
    }
};
