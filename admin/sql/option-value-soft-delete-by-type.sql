UPDATE { PREFIX }productoptionvalues
SET deleted_at = NOW()
WHERE option_type_id = :type_id
