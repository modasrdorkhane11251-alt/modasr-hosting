-- Rollback for migration 022 (destructive; requires a separate approval).
-- Code deployed for the unified catalog remains backward-compatible with
-- the legacy product table and ignores these optional fields when absent.
ALTER TABLE `product`
  DROP COLUMN `storefront_image`,
  DROP COLUMN `storefront_usd_cents`;
