SELECT COALESCE(MAX(sort), -1) + 1 as next_sort
FROM { PREFIX }productoptionvalues
WHERE option_type_id = :type_id
