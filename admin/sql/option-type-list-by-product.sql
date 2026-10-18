SELECT id, type_label, type_slug, default_option_descr,
       linked_product_id, default_linked_product_id, linked_product_descr, linked_image_slot, sort
FROM { PREFIX }productoptiontypes
WHERE product_id = :product_id AND deleted_at IS NULL
ORDER BY sort, id
