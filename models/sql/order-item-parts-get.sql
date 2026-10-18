-- Every kit part for an order's lines, in the order they were saved
SELECT p.order_item_id, p.product_id, p.option_value_id, p.quantity
FROM {PREFIX}shop_order_item_parts p
INNER JOIN {PREFIX}shop_order_items i ON i.id = p.order_item_id
WHERE i.order_id = :order_id
ORDER BY p.id ASC
