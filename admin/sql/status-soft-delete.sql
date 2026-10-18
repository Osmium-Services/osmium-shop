UPDATE { PREFIX }productstatus
SET deleted_at = NOW()
WHERE id = :id
