UPDATE { PREFIX }productcategories
SET deleted_at = NULL
WHERE id = :id
