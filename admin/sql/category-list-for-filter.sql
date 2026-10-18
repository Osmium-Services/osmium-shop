SELECT id, label
FROM { PREFIX }productcategories
WHERE deleted_at IS NULL
ORDER BY label
