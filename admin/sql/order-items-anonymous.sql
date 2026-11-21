-- The lines on an order as bought: product, options, quantity and prices. No customer details.
SELECT
    product_ref,
    product_title,
    product_options,
    quantity,
    unit_price_exc_tax,
    line_total_exc_tax,
    line_tax_amount
FROM {PREFIX}shop_order_items
WHERE order_id = :order_id
ORDER BY id ASC
