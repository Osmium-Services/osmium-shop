SELECT
    D.id,
    D.name,
    D.type,
    DATE_FORMAT(GREATEST(COALESCE(D.updated_at, D.created_at), D.created_at), '%e %b %Y, %l:%i%p') AS updatedFormatted
FROM {PREFIX}discounts AS D
WHERE D.deleted_at IS NULL
  AND (D.created_at >= NOW() - INTERVAL 24 HOUR OR D.updated_at >= NOW() - INTERVAL 24 HOUR)
ORDER BY GREATEST(COALESCE(D.updated_at, D.created_at), D.created_at) DESC, D.id DESC
LIMIT 10
