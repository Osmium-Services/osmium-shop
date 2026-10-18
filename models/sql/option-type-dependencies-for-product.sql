SELECT d.option_type_id, d.depends_on_value_id
FROM {PREFIX}productoptiontype_dependencies d
INNER JOIN {PREFIX}productoptiontypes t ON t.id = d.option_type_id
WHERE t.product_id = :product_id
  AND t.deleted_at IS NULL
