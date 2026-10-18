-- Shop tables, as the schema stands today (folded in from the core genesis schema and later core migrations).
-- CREATE TABLE IF NOT EXISTS, so installing over an existing shop database changes nothing.

CREATE TABLE IF NOT EXISTS `{PREFIX}discounts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('sitewide','product','coupon') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `value_type` enum('percent','fixed','free_delivery') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` decimal(10,2) NOT NULL,
  `starts_at` datetime DEFAULT NULL,
  `ends_at` datetime DEFAULT NULL,
  `usage_limit` int unsigned DEFAULT NULL,
  `usage_limit_per_customer` int unsigned DEFAULT NULL,
  `min_spend_inc_tax` decimal(10,2) DEFAULT NULL COMMENT 'Coupons only: the basket must total at least this, including VAT and before the discount, for the code to apply',
  `times_used` int unsigned NOT NULL DEFAULT '0',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `deleted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_discount_code` (`code`),
  KEY `idx_discounts_type_active_dates` (`type`,`active`,`starts_at`,`ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}discount_products` (
  `discount_id` int unsigned NOT NULL,
  `product_id` int unsigned NOT NULL,
  PRIMARY KEY (`discount_id`,`product_id`),
  CONSTRAINT `fk_discount_products_discount` FOREIGN KEY (`discount_id`) REFERENCES `{PREFIX}discounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}discount_redemptions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `discount_id` int unsigned NOT NULL,
  `order_id` int unsigned DEFAULT NULL,
  `customer_email` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_discount_redemptions_discount` (`discount_id`),
  KEY `idx_discount_redemptions_email` (`customer_email`),
  CONSTRAINT `fk_discount_redemptions_discount` FOREIGN KEY (`discount_id`) REFERENCES `{PREFIX}discounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
