-- A single order, everything on it
SELECT *
FROM {PREFIX}shop_orders
WHERE id = :id
LIMIT 1
