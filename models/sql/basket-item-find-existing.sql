SELECT id, quantity
FROM {PREFIX}checkout_basket_items
WHERE basket_id = :basket_id
  AND product_id = :product_id
  AND product_options <=> :product_options -- NULL-safe: a plain line only matches another plain line
  AND option_value_ids <=> :option_value_ids -- Same label text can come from different option rows, whose linked products differ
LIMIT 1
