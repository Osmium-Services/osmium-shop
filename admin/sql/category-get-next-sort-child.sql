SELECT COALESCE(MAX(sort), -1) + 1 as next_sort
FROM { PREFIX }productcategories
WHERE deleted_at IS NULL
AND parent_id = :parent_id
