SELECT id, product_id, product_ref, product_title, product_options, option_value_ids,
       quantity, unit_price_exc_tax, weight
FROM {PREFIX}checkout_basket_items
WHERE basket_id = :basket_id
ORDER BY id ASC
