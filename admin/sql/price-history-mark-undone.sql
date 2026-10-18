UPDATE { PREFIX }product_price_history
SET undone_at = NOW()
WHERE id = :id
