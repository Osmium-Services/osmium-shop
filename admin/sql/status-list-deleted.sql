SELECT
    S.id,
    S.descr_short,
    S.deleted_at,
    COUNT(P.id) as product_count
FROM { PREFIX }productstatus AS S
LEFT JOIN { PREFIX }products AS P ON P.status_id = S.id
WHERE S.deleted_at IS NOT NULL
GROUP BY S.id
ORDER BY S.deleted_at DESC
