-- One product with what a tool needs to check before changing it: its name, whether it is live, and whether its
-- status is a restocking one. No customer data.
SELECT
    P.id,
    P.reference,
    P.model_name,
    P.restock_at,
    P.status_id,
    PS.descr_short AS status_name,
    P.deleted_at,
    PB.name AS brand_name,
    COALESCE(PS.restocking, 0) AS restocking
FROM {PREFIX}products AS P
LEFT JOIN {PREFIX}productbrands AS PB ON PB.id = P.brand_id
LEFT JOIN {PREFIX}productstatus AS PS ON PS.id = P.status_id
WHERE P.id = :id
