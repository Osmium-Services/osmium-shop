-- How the customer paid, in the provider's own words ("PayPal account", "Card (Visa ••4242)").
-- Free text from the provider, so a new provider needs no change here.

ALTER TABLE `{PREFIX}shop_orders`
  ADD COLUMN `payment_method` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'How the customer paid, as the provider describes it' AFTER `payment_payer_email`;
