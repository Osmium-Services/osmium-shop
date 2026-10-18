SELECT id, techspec
FROM { PREFIX }products
WHERE deleted_at IS NULL
  AND techspec IS NOT NULL
  AND techspec != ''
ORDER BY id
