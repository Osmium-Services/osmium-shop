-- Line items for an order, as they were bought
SELECT *
FROM {PREFIX}shop_order_items
WHERE order_id = :order_id
ORDER BY id ASC
