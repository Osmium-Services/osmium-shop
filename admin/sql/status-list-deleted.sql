SELECT
    S.id,
    S.descr_short,
    S.deleted_at,
    CL.user_name AS changed_by,
    U.avatar AS changed_by_avatar,
    COUNT(P.id) as product_count
FROM { PREFIX }productstatus AS S
LEFT JOIN { PREFIX }products AS P ON P.status_id = S.id
LEFT JOIN { PREFIX }changelog AS CL ON CL.id = (SELECT MAX(id) FROM { PREFIX }changelog WHERE record_type = 'status' AND record_id = S.id)
LEFT JOIN { PREFIX }users AS U ON U.id = CL.user_id
WHERE S.deleted_at IS NOT NULL
GROUP BY S.id, CL.user_name, U.avatar
ORDER BY S.deleted_at DESC
