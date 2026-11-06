-- A brand's logo: the file name of an image kept in assets/images/shop/brands/. Shown in the admin only for now.

ALTER TABLE `{PREFIX}productbrands`
  ADD COLUMN `logo` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Logo file name in assets/images/shop/brands/' AFTER `info_external`;
