-- Brand and model of the orderable products in each category, newest first.
--
-- Used only to give a category card a picture when the category itself has no
-- image set in admin: the first orderable product in it stands in. Product
-- image paths are derived from brand and model rather than stored, which is
-- why those two columns are what this returns.
SELECT
    PC.category_id,
    PB.name AS brand_name,
    P.model_name
FROM
    { PREFIX } products_productcategories AS PC
    INNER JOIN { PREFIX } products AS P ON P.id = PC.product_id
    INNER JOIN { PREFIX } productbrands AS PB ON PB.id = P.brand_id
    INNER JOIN { PREFIX } productstatus AS PS ON PS.id = P.status_id
WHERE
    PC.deleted_at IS NULL
    AND P.deleted_at IS NULL
    AND PB.deleted_at IS NULL
    AND PS.deleted_at IS NULL
    AND PS.product_available = 1
ORDER BY
    PC.category_id,
    P.created_at DESC;
