SELECT price_exc_tax, rrp_exc_tax
FROM { PREFIX }products
WHERE id = :id
