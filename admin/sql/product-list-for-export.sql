SELECT
    P.id,
    P.reference,
    PB.name as brand_name,
    P.model_name,
    P.price_exc_tax,
    P.rrp_exc_tax,
    P.packed_weight,
    PS.descr_long as status_name
FROM { PREFIX }products AS P
LEFT JOIN { PREFIX }productbrands AS PB ON PB.id = P.brand_id
LEFT JOIN { PREFIX }productstatus AS PS ON PS.id = P.status_id
WHERE P.deleted_at IS NULL
ORDER BY PB.name ASC, P.model_name ASC
