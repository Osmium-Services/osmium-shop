SELECT
    S.id,
    S.descr_short,
    S.deleted_at,
    CL.user_name AS changed_by,
    U.avatar AS changed_by_avatar,
    DATE_FORMAT(GREATEST(COALESCE(S.updated_at, S.created_at), S.created_at, COALESCE(S.deleted_at, S.created_at)), '%e %b %Y, %l:%i%p') AS updatedFormatted,
    COUNT(P.id) as product_count
FROM { PREFIX }productstatus AS S
LEFT JOIN { PREFIX }products AS P ON P.status_id = S.id
LEFT JOIN { PREFIX }changelog AS CL ON CL.id = (SELECT MAX(id) FROM { PREFIX }changelog WHERE record_type = 'status' AND record_id = S.id)
LEFT JOIN { PREFIX }users AS U ON U.id = CL.user_id
WHERE (S.created_at >= NOW() - INTERVAL 24 HOUR OR S.updated_at >= NOW() - INTERVAL 24 HOUR OR S.deleted_at >= NOW() - INTERVAL 24 HOUR)
GROUP BY S.id, S.deleted_at, CL.user_name, U.avatar
ORDER BY GREATEST(COALESCE(S.updated_at, S.created_at), S.created_at, COALESCE(S.deleted_at, S.created_at)) DESC, S.id DESC
LIMIT 10
