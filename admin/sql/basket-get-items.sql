SELECT product_ref, product_title, product_options, quantity, unit_price_exc_tax AS unit_price, weight, created_at
FROM { PREFIX } checkout_basket_items
WHERE basket_id = :basket_id
ORDER BY created_at ASC
