UPDATE { PREFIX }products
SET price_exc_tax = :price, rrp_exc_tax = :rrp, packed_weight = :weight, updated_at = NOW()
WHERE id = :id
