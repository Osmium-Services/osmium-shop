-- Line items for an order, in the order they were added
SELECT *
FROM {PREFIX}shop_order_items
WHERE order_id = :order_id
ORDER BY id ASC
