SELECT
    S.id,
    S.descr_short,
    DATE_FORMAT(GREATEST(COALESCE(S.updated_at, S.created_at), S.created_at), '%e %b %Y, %l:%i%p') AS updatedFormatted,
    COUNT(P.id) as product_count
FROM { PREFIX }productstatus AS S
LEFT JOIN { PREFIX }products AS P ON P.status_id = S.id
WHERE S.deleted_at IS NULL
  AND (S.created_at >= NOW() - INTERVAL 24 HOUR OR S.updated_at >= NOW() - INTERVAL 24 HOUR)
GROUP BY S.id
ORDER BY GREATEST(COALESCE(S.updated_at, S.created_at), S.created_at) DESC, S.id DESC
LIMIT 10
