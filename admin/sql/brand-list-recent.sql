SELECT
    B.id,
    B.name,
    DATE_FORMAT(GREATEST(COALESCE(B.updated_at, B.created_at), B.created_at), '%e %b %Y, %l:%i%p') AS updatedFormatted,
    COUNT(P.id) as product_count
FROM { PREFIX }productbrands AS B
LEFT JOIN { PREFIX }products AS P ON P.brand_id = B.id
WHERE B.deleted_at IS NULL
  AND (B.created_at >= NOW() - INTERVAL 24 HOUR OR B.updated_at >= NOW() - INTERVAL 24 HOUR)
GROUP BY B.id
ORDER BY GREATEST(COALESCE(B.updated_at, B.created_at), B.created_at) DESC, B.id DESC
LIMIT 10
