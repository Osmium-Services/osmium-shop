UPDATE { PREFIX }productoptionvalues
SET deleted_at = NOW()
WHERE id = :id
