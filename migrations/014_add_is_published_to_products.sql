-- Draft / Published for a product. A Draft is hidden from the shop (lists, search, sitemap, its own page) until published.
-- Every existing product stays Published. A duplicated product starts as a Draft.

ALTER TABLE `{PREFIX}products`
  ADD COLUMN `is_published` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = Draft, hidden from the shop' AFTER `status_id`;
