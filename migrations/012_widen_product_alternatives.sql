-- Alternatives holds a comma-separated list of product IDs shown as an "alternatives" carousel on the product page.
-- Widened from 50 so a product can list more than a handful.
-- The widening rebuilds the table, and imported catalogues carry zero dates that strict mode would reject, so those checks are off for this one statement.

SET @shop_sql_mode = @@SESSION.sql_mode;
SET SESSION sql_mode = REPLACE(REPLACE(@@SESSION.sql_mode, 'NO_ZERO_DATE', ''), 'NO_ZERO_IN_DATE', '');

ALTER TABLE `{PREFIX}products`
  MODIFY COLUMN `alternatives` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Comma-separated product IDs shown as alternatives';

SET SESSION sql_mode = @shop_sql_mode;
