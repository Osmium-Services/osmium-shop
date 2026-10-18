INSERT INTO {PREFIX}checkout_basket_items (
    basket_id, product_id, product_ref, product_title, product_options, option_value_ids,
    quantity, unit_price_exc_tax, weight
) VALUES (
    :basket_id, :product_id, :product_ref, :product_title, :product_options, :option_value_ids,
    :quantity, :unit_price_exc_tax, :weight
)
