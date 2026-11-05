-- Destructive rollback; separate approval and a verified backup are required.
-- This removes email OTP requests and email-to-user bindings, not user rows.
DROP TABLE IF EXISTS `storefront_email_otp`;
DROP TABLE IF EXISTS `storefront_email_identity`;
