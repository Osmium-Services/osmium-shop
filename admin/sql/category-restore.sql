UPDATE { PREFIX }productcategories
SET deleted_at = NULL, updated_at = NOW()
WHERE id = :id
