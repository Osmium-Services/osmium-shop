SELECT id, option_type_id, linked_product_id, option_premium, option_descr, option_reference, option_packed_weight
FROM {PREFIX}productoptionvalues
WHERE id = :id
  AND deleted_at IS NULL
