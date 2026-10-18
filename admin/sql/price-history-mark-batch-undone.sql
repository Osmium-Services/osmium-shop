UPDATE { PREFIX }product_price_history
SET undone_at = NOW()
WHERE changed_at = :timestamp AND change_source = 'import'
