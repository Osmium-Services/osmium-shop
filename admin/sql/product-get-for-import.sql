SELECT id, price_exc_tax, rrp_exc_tax, packed_weight
FROM { PREFIX }products
WHERE id = :id AND deleted_at IS NULL
