SELECT d.option_type_id, d.depends_on_value_id
FROM {PREFIX}productoptiontype_dependencies d
INNER JOIN {PREFIX}productoptiontypes t ON d.option_type_id = t.id
WHERE t.product_id = :productID AND t.deleted_at IS NULL
