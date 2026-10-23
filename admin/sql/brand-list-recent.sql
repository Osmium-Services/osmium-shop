SELECT
    B.id,
    B.name,
    B.deleted_at,
    CL.user_name AS changed_by,
    U.avatar AS changed_by_avatar,
    DATE_FORMAT(GREATEST(COALESCE(B.updated_at, B.created_at), B.created_at, COALESCE(B.deleted_at, B.created_at)), '%e %b %Y, %l:%i%p') AS updatedFormatted,
    COUNT(P.id) as product_count
FROM { PREFIX }productbrands AS B
LEFT JOIN { PREFIX }products AS P ON P.brand_id = B.id
LEFT JOIN { PREFIX }changelog AS CL ON CL.id = (SELECT MAX(id) FROM { PREFIX }changelog WHERE record_type = 'brand' AND record_id = B.id)
LEFT JOIN { PREFIX }users AS U ON U.id = CL.user_id
WHERE (B.created_at >= NOW() - INTERVAL 24 HOUR OR B.updated_at >= NOW() - INTERVAL 24 HOUR OR B.deleted_at >= NOW() - INTERVAL 24 HOUR)
GROUP BY B.id, B.deleted_at, CL.user_name, U.avatar
ORDER BY GREATEST(COALESCE(B.updated_at, B.created_at), B.created_at, COALESCE(B.deleted_at, B.created_at)) DESC, B.id DESC
LIMIT 10
