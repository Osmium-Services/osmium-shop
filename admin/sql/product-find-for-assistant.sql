-- Live products whose brand, model or reference matches the search pattern, with their status. No customer data.
SELECT
    P.id,
    P.reference,
    P.model_name,
    P.restock_at,
    PB.name AS brand_name,
    PS.descr_short AS status_name,
    PS.product_available AS available,
    PS.restocking AS restocking
FROM {PREFIX}products AS P
LEFT JOIN {PREFIX}productbrands AS PB ON PB.id = P.brand_id
LEFT JOIN {PREFIX}productstatus AS PS ON PS.id = P.status_id
WHERE P.deleted_at IS NULL
AND (CONCAT_WS(' ', PB.name, P.model_name) LIKE :pattern OR P.reference LIKE :pattern)
ORDER BY PB.name, P.model_name
LIMIT :limit
