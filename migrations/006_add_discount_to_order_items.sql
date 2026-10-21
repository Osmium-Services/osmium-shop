-- Which discount reduced each order line, and by how much in total for the line (before VAT), so a discount can be
-- reported on whatever its type. Orders made before this have neither.

ALTER TABLE `{PREFIX}shop_order_items`
  ADD COLUMN `discount_id` int unsigned DEFAULT NULL AFTER `weight`,
  ADD COLUMN `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00' COMMENT 'Total taken off this line by its discount, excluding VAT' AFTER `discount_id`,
  ADD KEY `idx_discount` (`discount_id`);
