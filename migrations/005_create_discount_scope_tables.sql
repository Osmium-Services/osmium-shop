-- A per-product discount can also cover whole categories or brands. They are matched live at basket time,
-- so a product added to the category later is discounted automatically.

CREATE TABLE IF NOT EXISTS `{PREFIX}discount_categories` (
  `discount_id` int unsigned NOT NULL,
  `category_id` int unsigned NOT NULL,
  PRIMARY KEY (`discount_id`,`category_id`),
  CONSTRAINT `{PREFIX}fk_discount_categories_discount` FOREIGN KEY (`discount_id`) REFERENCES `{PREFIX}discounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}discount_brands` (
  `discount_id` int unsigned NOT NULL,
  `brand_id` int unsigned NOT NULL,
  PRIMARY KEY (`discount_id`,`brand_id`),
  CONSTRAINT `{PREFIX}fk_discount_brands_discount` FOREIGN KEY (`discount_id`) REFERENCES `{PREFIX}discounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
