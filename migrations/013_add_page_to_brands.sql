-- A brand's own page: the id of a row in the pages table that lists the brand's products. Empty for a brand with no page.

ALTER TABLE `{PREFIX}productbrands`
  ADD COLUMN `page_id` int unsigned DEFAULT NULL COMMENT 'Pages row listing this brand''s products' AFTER `page_url`,
  ADD UNIQUE KEY `page_id` (`page_id`);
