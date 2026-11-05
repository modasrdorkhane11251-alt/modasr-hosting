-- Destructive rollback; requires separate approval and a verified backup.
DROP TABLE IF EXISTS `storefront_review`;
DROP TABLE IF EXISTS `storefront_refund`;
DROP TABLE IF EXISTS `storefront_inventory_item`;
ALTER TABLE `DiscountSell` DROP COLUMN `per_user_limit`, DROP COLUMN `valid_to`, DROP COLUMN `valid_from`;
ALTER TABLE `support_message` DROP COLUMN `order_no`;
ALTER TABLE `setting` DROP COLUMN `storefront_tax_bps`;
ALTER TABLE `product` DROP COLUMN `tax_included`, DROP COLUMN `inventory_enabled`, DROP COLUMN `multi_month`;
