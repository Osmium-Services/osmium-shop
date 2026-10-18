SELECT v.id, v.option_type_id, v.linked_product_id, v.option_premium, v.option_descr, v.option_reference, v.option_packed_weight
FROM {PREFIX}productoptionvalues AS v
INNER JOIN {PREFIX}productoptiontypes AS t ON t.id = v.option_type_id
  AND t.product_id = :product_id
  AND t.deleted_at IS NULL
WHERE v.id = :id
  AND v.deleted_at IS NULL
