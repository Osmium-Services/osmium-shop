SELECT COUNT(*) as child_count
FROM { PREFIX }productcategories
WHERE parent_id = :id
AND deleted_at IS NULL
