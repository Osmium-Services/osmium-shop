-- Free delivery becomes a switch on any discount, instead of a value type of its own.
-- Old free delivery coupons keep working: switch on, 0% off the goods.

ALTER TABLE `{PREFIX}discounts`
  ADD COLUMN `free_delivery` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Waives the delivery charge while this discount applies' AFTER `value`;

UPDATE `{PREFIX}discounts` SET `free_delivery` = 1, `value_type` = 'percent' WHERE `value_type` = 'free_delivery';

ALTER TABLE `{PREFIX}discounts`
  MODIFY `value_type` enum('percent','fixed') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
