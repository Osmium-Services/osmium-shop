-- Every live product with the fields needed to spot stock and set-up problems:
-- availability, restocking, price and whether it sits in a category. No customer data.
SELECT
    P.id,
    P.reference,
    P.model_name,
    P.price_exc_tax,
    P.restock_at,
    PB.name AS brand_name,
    PS.product_available AS available,
    PS.restocking AS restocking,
    MAX(CASE WHEN PPC.product_id IS NOT NULL THEN 1 ELSE 0 END) AS has_category
FROM {PREFIX}products AS P
INNER JOIN {PREFIX}productbrands AS PB ON PB.id = P.brand_id AND PB.deleted_at IS NULL
INNER JOIN {PREFIX}productstatus AS PS ON PS.id = P.status_id AND PS.deleted_at IS NULL
INNER JOIN {PREFIX}products_pages AS PP ON PP.product_id = P.id AND PP.is_qr = 0
LEFT JOIN {PREFIX}products_productcategories AS PPC ON PPC.product_id = P.id AND PPC.deleted_at IS NULL
WHERE P.deleted_at IS NULL
GROUP BY P.id
ORDER BY P.id ASC
