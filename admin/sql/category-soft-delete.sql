UPDATE { PREFIX }productcategories
SET deleted_at = NOW()
WHERE id = :id
