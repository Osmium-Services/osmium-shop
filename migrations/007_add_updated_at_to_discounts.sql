-- Lets the Discounts page list recent changes the same way Products, Brands, Statuses and Categories do.

ALTER TABLE `{PREFIX}discounts`
  ADD COLUMN `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`;
