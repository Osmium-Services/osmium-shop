-- Shop tables, as the schema stands today (folded in from the core genesis schema and later core migrations).
-- CREATE TABLE IF NOT EXISTS, so installing over an existing shop database changes nothing.

CREATE TABLE IF NOT EXISTS `{PREFIX}checkout_baskets` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `session_id` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` enum('active','converted') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_session_status` (`session_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}checkout_basket_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `basket_id` int unsigned NOT NULL,
  `product_id` smallint unsigned NOT NULL,
  `product_ref` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `product_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `product_options` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `option_value_ids` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Sorted CSV of the chosen productoptionvalues.id rows',
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `unit_price_exc_tax` decimal(10,3) NOT NULL DEFAULT '0.000',
  `weight` float unsigned NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_basket` (`basket_id`),
  CONSTRAINT `fk_checkout_basket_items_basket` FOREIGN KEY (`basket_id`) REFERENCES `{PREFIX}checkout_baskets` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
