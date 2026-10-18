SELECT id, default_linked_product_id
FROM {PREFIX}productoptiontypes
WHERE product_id = :product_id
  AND deleted_at IS NULL
ORDER BY sort, id
