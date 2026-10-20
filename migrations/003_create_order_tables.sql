-- Shop tables, as the schema stands today (folded in from the core genesis schema and later core migrations).
-- CREATE TABLE IF NOT EXISTS, so installing over an existing shop database changes nothing.

CREATE TABLE IF NOT EXISTS `{PREFIX}shop_orders` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_ref` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invoice_number` int DEFAULT NULL,
  `basket_id` int DEFAULT NULL,
  `status` enum('pending','paid','failed','cancelled','dispatched','refunded') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `customer_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `customer_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `customer_phone` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_address1` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `billing_address2` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `billing_county` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `billing_postcode` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `billing_country` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'United Kingdom',
  `delivery_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `delivery_address1` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `delivery_address2` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_city` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `delivery_county` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_postcode` varchar(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `delivery_country` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'United Kingdom',
  `delivery_zone` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `subtotal_exc_tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `shipping_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_rate_percent` decimal(5,2) NOT NULL DEFAULT '20.00',
  `total_inc_tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `currency` char(3) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'GBP',
  `discount_id` int unsigned DEFAULT NULL,
  `discount_code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_provider` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_test_payment` tinyint(1) NOT NULL DEFAULT '0',
  `payment_order_ref` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_capture_ref` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `payment_payer_email` varchar(190) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `dispatched_at` datetime DEFAULT NULL,
  `tracking_carrier` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_ref` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `terms_accepted_at` datetime DEFAULT NULL,
  `admin_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `xero_invoice_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `xero_synced_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_order_ref` (`order_ref`),
  UNIQUE KEY `idx_invoice_number` (`invoice_number`),
  UNIQUE KEY `idx_payment_order_ref` (`payment_order_ref`),
  KEY `idx_status` (`status`),
  KEY `idx_created` (`created_at`),
  KEY `idx_email` (`customer_email`),
  KEY `idx_basket` (`basket_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}shop_order_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_id` int NOT NULL,
  `product_id` int unsigned NOT NULL DEFAULT '0',
  `product_ref` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `product_title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `product_options` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `option_signature` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `quantity` int NOT NULL DEFAULT '1',
  `unit_price_exc_tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `line_total_exc_tax` decimal(10,2) NOT NULL DEFAULT '0.00',
  `line_tax_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `tax_rate_percent` decimal(5,2) NOT NULL DEFAULT '20.00',
  `weight` decimal(10,3) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order` (`order_id`),
  CONSTRAINT `{PREFIX}fk_shop_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `{PREFIX}shop_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}shop_order_item_parts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `order_item_id` int NOT NULL,
  `product_id` int unsigned NOT NULL COMMENT 'Linked product behind a chosen option, frozen when the order was made',
  `option_value_id` int unsigned DEFAULT NULL COMMENT 'NULL when the part is the option type''s default choice',
  `quantity` int unsigned NOT NULL DEFAULT '1' COMMENT 'Units of this product per unit of the order line',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_item` (`order_item_id`),
  KEY `idx_product` (`product_id`),
  CONSTRAINT `{PREFIX}fk_shop_order_item_parts_item` FOREIGN KEY (`order_item_id`) REFERENCES `{PREFIX}shop_order_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{PREFIX}invoice_counter` (
  `id` tinyint(1) NOT NULL DEFAULT '1',
  `next_invoice_number` int NOT NULL DEFAULT '1001',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `{PREFIX}invoice_counter` (`id`, `next_invoice_number`) VALUES (1, 1001);
