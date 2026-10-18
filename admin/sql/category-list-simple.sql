SELECT id, label, parent_id
FROM { PREFIX }productcategories
WHERE deleted_at IS NULL
