-- Live products for the files hook: names and the one category each is listed under first. Discontinued (status 3) are left out.
SELECT
    P.id,
    P.model_name,
    PB.name AS brand_name,
    (
        SELECT PPC.category_id
        FROM {PREFIX}products_productcategories AS PPC
        INNER JOIN {PREFIX}productcategories AS PC ON PC.id = PPC.category_id AND PC.deleted_at IS NULL
        WHERE PPC.product_id = P.id AND PPC.deleted_at IS NULL
        ORDER BY COALESCE(PPC.sort, 9999), PPC.category_id
        LIMIT 1
    ) AS category_id
FROM {PREFIX}products AS P
INNER JOIN {PREFIX}productbrands AS PB ON PB.id = P.brand_id AND PB.deleted_at IS NULL
WHERE P.deleted_at IS NULL
  AND P.status_id != 3
ORDER BY PB.name ASC, P.model_name ASC
