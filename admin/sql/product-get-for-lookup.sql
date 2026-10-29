-- One live product as another service needs to see it: names, reference, ex-tax price, and whether it can be bought.
-- No customer data.
SELECT
    P.id,
    P.brand_id,
    PB.name AS brand_name,
    P.model_name,
    P.reference,
    P.price_exc_tax,
    COALESCE(PS.product_available, 0) AS available
FROM {PREFIX}products AS P
LEFT JOIN {PREFIX}productbrands AS PB ON PB.id = P.brand_id
LEFT JOIN {PREFIX}productstatus AS PS ON PS.id = P.status_id AND PS.deleted_at IS NULL
WHERE P.id = :id
  AND P.deleted_at IS NULL
LIMIT 1
