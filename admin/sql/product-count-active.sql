SELECT COUNT(*) as count
FROM { PREFIX }products
WHERE deleted_at IS NULL
