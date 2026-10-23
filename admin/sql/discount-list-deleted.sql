SELECT
    D.id,
    D.name,
    D.type,
    D.deleted_at,
    CL.user_name AS changed_by,
    U.avatar AS changed_by_avatar
FROM {PREFIX}discounts AS D
LEFT JOIN {PREFIX}changelog AS CL ON CL.id = (SELECT MAX(id) FROM {PREFIX}changelog WHERE record_type = 'discount' AND record_id = D.id)
LEFT JOIN {PREFIX}users AS U ON U.id = CL.user_id
WHERE D.deleted_at IS NOT NULL
ORDER BY D.deleted_at DESC
