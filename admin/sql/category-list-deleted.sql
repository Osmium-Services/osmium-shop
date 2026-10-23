SELECT C.id, C.label, C.deleted_at, CL.user_name AS changed_by, U.avatar AS changed_by_avatar
FROM { PREFIX }productcategories AS C
LEFT JOIN { PREFIX }changelog AS CL ON CL.id = (SELECT MAX(id) FROM { PREFIX }changelog WHERE record_type = 'category' AND record_id = C.id)
LEFT JOIN { PREFIX }users AS U ON U.id = CL.user_id
WHERE C.deleted_at IS NOT NULL
ORDER BY C.deleted_at DESC
