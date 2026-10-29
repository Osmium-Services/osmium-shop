-- Trade discount eligibility is the Trade Ordering service's business, not the shop's. The brands column was a
-- leftover from the first site and nothing in the shop reads or sets it. Dropped only where it exists, because a shop
-- installed over older tables may never have had it.

SET @has_column = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{PREFIX}productbrands' AND COLUMN_NAME = 'trade_discount_allowed'
);

SET @drop_column = IF(@has_column > 0, 'ALTER TABLE `{PREFIX}productbrands` DROP COLUMN `trade_discount_allowed`', 'DO 0');

PREPARE drop_column_statement FROM @drop_column;
EXECUTE drop_column_statement;
DEALLOCATE PREPARE drop_column_statement;
