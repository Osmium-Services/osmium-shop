UPDATE { PREFIX }products
SET deleted_at = NULL
WHERE id = :id
