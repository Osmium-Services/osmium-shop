SELECT
    D.id,
    D.name,
    D.type,
    D.deleted_at,
    CL.user_name AS changed_by,
    U.avatar AS changed_by_avatar,
    DATE_FORMAT(GREATEST(COALESCE(D.updated_at, D.created_at), D.created_at, COALESCE(D.deleted_at, D.created_at)), '%e %b %Y, %l:%i%p') AS updatedFormatted
FROM {PREFIX}discounts AS D
LEFT JOIN {PREFIX}changelog AS CL ON CL.id = (SELECT MAX(id) FROM {PREFIX}changelog WHERE record_type = 'discount' AND record_id = D.id)
LEFT JOIN {PREFIX}users AS U ON U.id = CL.user_id
WHERE (D.created_at >= NOW() - INTERVAL 24 HOUR OR D.updated_at >= NOW() - INTERVAL 24 HOUR OR D.deleted_at >= NOW() - INTERVAL 24 HOUR)
ORDER BY GREATEST(COALESCE(D.updated_at, D.created_at), D.created_at, COALESCE(D.deleted_at, D.created_at)) DESC, D.id DESC
LIMIT 10
