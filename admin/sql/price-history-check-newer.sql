SELECT id FROM { PREFIX }product_price_history
WHERE product_id = :product_id AND changed_at > :changed_at AND undone_at IS NULL
