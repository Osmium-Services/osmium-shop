SELECT id, label, deleted_at
FROM { PREFIX }productcategories
WHERE deleted_at IS NOT NULL
ORDER BY deleted_at DESC
