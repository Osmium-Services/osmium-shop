UPDATE {PREFIX}checkout_basket_items
SET quantity = :quantity
WHERE id = :id
  AND basket_id = :basket_id
