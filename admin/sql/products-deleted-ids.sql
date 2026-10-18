SELECT id
FROM { PREFIX }products
WHERE deleted_at IS NOT NULL
