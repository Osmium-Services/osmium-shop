SELECT v.id, v.option_type_id, v.option_descr, v.option_premium,
       v.option_rrp, v.option_packed_weight, v.option_reference, v.linked_product_id,
       v.linked_image_slot, v.sort
FROM { PREFIX }productoptionvalues v
INNER JOIN { PREFIX }productoptiontypes t ON v.option_type_id = t.id
WHERE t.product_id = :product_id
  AND t.deleted_at IS NULL
  AND v.deleted_at IS NULL
ORDER BY v.option_type_id, v.sort, v.id
