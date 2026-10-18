UPDATE { PREFIX }productstatus
SET deleted_at = NULL
WHERE id = :id
