DELETE FROM { PREFIX }productoptiontype_dependencies
WHERE depends_on_value_id IN (
    SELECT id FROM { PREFIX }productoptionvalues WHERE option_type_id = :type_id
)
