-- The products an order line took stock from, frozen when the order was made
INSERT INTO {PREFIX}shop_order_item_parts (order_item_id, product_id, option_value_id, quantity)
VALUES (:order_item_id, :product_id, :option_value_id, :quantity)
