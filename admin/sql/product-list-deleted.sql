SELECT
    P.id,
    P.reference,
    P.model_name,
    P.price_exc_tax,
    P.deleted_at,
    PB.name as brand_name,
    CL.user_name AS changed_by,
    U.avatar AS changed_by_avatar
FROM { PREFIX }products AS P
LEFT JOIN { PREFIX }productbrands AS PB ON PB.id = P.brand_id
LEFT JOIN { PREFIX }changelog AS CL ON CL.id = (SELECT MAX(id) FROM { PREFIX }changelog WHERE record_type = 'product' AND record_id = P.id)
LEFT JOIN { PREFIX }users AS U ON U.id = CL.user_id
WHERE P.deleted_at IS NOT NULL
ORDER BY P.deleted_at DESC
