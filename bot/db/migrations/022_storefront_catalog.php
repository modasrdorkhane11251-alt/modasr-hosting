<?php

/**
 * Migration 022 — Unified storefront catalog.
 *
 * Adds nullable, backward-compatible fields only:
 *   storefront_usd_cents — exact integer USD cents used by the website for
 *                          live Toman conversion; NULL keeps legacy fixed-
 *                          Toman pricing for bot-only products.
 *   storefront_image     — optional uploaded product-poster filename.
 *
 * No product, price, order, or invoice is deleted or rewritten by this
 * migration. Initial USD values are applied separately by the reviewed
 * scripts/storefront_sync_static.php dry-run/apply tool.
 *
 * Rollback: db/migrations/rollback/022_storefront_catalog.rollback.sql
 */

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->hasColumn('product', 'storefront_usd_cents')) {
        $pdo->exec("ALTER TABLE `product` ADD COLUMN `storefront_usd_cents` INT UNSIGNED NULL DEFAULT NULL AFTER `max_retail_price`");
    }
    if (!$schema->hasColumn('product', 'storefront_image')) {
        $pdo->exec("ALTER TABLE `product` ADD COLUMN `storefront_image` VARCHAR(255) NULL DEFAULT NULL AFTER `storefront_usd_cents`");
    }
};
