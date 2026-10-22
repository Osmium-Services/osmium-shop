SELECT id, label, created_at, updated_at
FROM { PREFIX }productcategories
WHERE deleted_at IS NULL
  AND (created_at >= NOW() - INTERVAL 24 HOUR OR updated_at >= NOW() - INTERVAL 24 HOUR)
ORDER BY GREATEST(COALESCE(updated_at, created_at), created_at) DESC, id DESC
LIMIT 10
