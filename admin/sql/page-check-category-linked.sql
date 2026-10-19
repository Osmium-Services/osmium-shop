SELECT c.label
FROM { PREFIX }productcategories c
WHERE c.page_id = :page_id
AND c.deleted_at IS NULL
