SELECT
    P.id,
    P.reference,
    P.model_name,
    P.price_exc_tax,
    P.deleted_at,
    PB.name as brand_name
FROM { PREFIX }products AS P
LEFT JOIN { PREFIX }productbrands AS PB ON PB.id = P.brand_id
WHERE P.deleted_at IS NOT NULL
ORDER BY P.deleted_at DESC
