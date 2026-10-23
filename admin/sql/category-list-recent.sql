SELECT C.id, C.label, C.created_at, C.updated_at, C.deleted_at, CL.user_name AS changed_by, U.avatar AS changed_by_avatar
FROM { PREFIX }productcategories AS C
LEFT JOIN { PREFIX }changelog AS CL ON CL.id = (SELECT MAX(id) FROM { PREFIX }changelog WHERE record_type = 'category' AND record_id = C.id)
LEFT JOIN { PREFIX }users AS U ON U.id = CL.user_id
WHERE (C.created_at >= NOW() - INTERVAL 24 HOUR OR C.updated_at >= NOW() - INTERVAL 24 HOUR OR C.deleted_at >= NOW() - INTERVAL 24 HOUR)
ORDER BY GREATEST(COALESCE(C.updated_at, C.created_at), C.created_at, COALESCE(C.deleted_at, C.created_at)) DESC, C.id DESC
LIMIT 10
