DELETE FROM {PREFIX}checkout_basket_items
WHERE id = :id
  AND basket_id = :basket_id
