SELECT
    B.id,
    B.name,
    B.deleted_at,
    CL.user_name AS changed_by,
    U.avatar AS changed_by_avatar,
    COUNT(P.id) as product_count
FROM { PREFIX }productbrands AS B
LEFT JOIN { PREFIX }products AS P ON P.brand_id = B.id
LEFT JOIN { PREFIX }changelog AS CL ON CL.id = (SELECT MAX(id) FROM { PREFIX }changelog WHERE record_type = 'brand' AND record_id = B.id)
LEFT JOIN { PREFIX }users AS U ON U.id = CL.user_id
WHERE B.deleted_at IS NOT NULL
GROUP BY B.id, CL.user_name, U.avatar
ORDER BY B.deleted_at DESC
