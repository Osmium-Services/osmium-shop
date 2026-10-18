DELETE d FROM { PREFIX }productoptiontype_dependencies d
INNER JOIN { PREFIX }productoptiontypes t ON d.option_type_id = t.id
WHERE t.product_id = :product_id
