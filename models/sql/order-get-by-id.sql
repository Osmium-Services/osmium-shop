-- Single order by id
SELECT *
FROM {PREFIX}shop_orders
WHERE id = :id
LIMIT 1
