-- A product can show a shared list from the FAQs service instead of its own FAQ text.
-- Holds the list slug, empty means the product uses its own FAQ.

ALTER TABLE `{PREFIX}products`
  ADD COLUMN `faq_list` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Slug of a FAQs service list shown instead of the faq text' AFTER `faq`;
