-- Destructive rollback for migration 026_phone_otp.
-- Requires separate approval and a verified backup. This removes phone OTP
-- requests and phone-to-user bindings; it does not delete MODASR user rows.
DROP TABLE IF EXISTS `storefront_phone_otp`;
DROP TABLE IF EXISTS `storefront_phone_identity`;
