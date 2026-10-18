SELECT
    B.id,
    B.name,
    B.deleted_at,
    COUNT(P.id) as product_count
FROM { PREFIX }productbrands AS B
LEFT JOIN { PREFIX }products AS P ON P.brand_id = B.id
WHERE B.deleted_at IS NOT NULL
GROUP BY B.id
ORDER BY B.deleted_at DESC
