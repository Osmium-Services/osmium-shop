-- A snapshot of what was bought at the price paid. Never joined back to live
-- products: a later price change or deletion must not rewrite history.
INSERT INTO {PREFIX}shop_order_items (
    order_id, product_id, product_ref, product_title, product_options, option_signature,
    quantity, unit_price_exc_tax, line_total_exc_tax, line_tax_amount, tax_rate_percent, weight,
    discount_id, discount_amount
) VALUES (
    :order_id, :product_id, :product_ref, :product_title, :product_options, :option_signature,
    :quantity, :unit_price_exc_tax, :line_total_exc_tax, :line_tax_amount, :tax_rate_percent, :weight,
    :discount_id, :discount_amount
)
