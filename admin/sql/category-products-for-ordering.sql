SELECT
    P.id,
    P.model_name,
    P.price_exc_tax,
    PB.name AS brand_name,
    PPC.sort,
    PS.product_available
FROM { PREFIX }products AS P
INNER JOIN { PREFIX }products_productcategories AS PPC
    ON PPC.product_id = P.id AND PPC.category_id = :category_id
INNER JOIN { PREFIX }productbrands AS PB ON PB.id = P.brand_id
INNER JOIN { PREFIX }productstatus AS PS ON PS.id = P.status_id
WHERE P.deleted_at IS NULL
ORDER BY PPC.sort ASC, P.model_name ASC
