-- Live products for the shop.products.list hook: names, reference, ex-tax price, status, whether it has options, and every
-- category it is listed under (the first one also as category_id) with the product's own sort number in each. Discontinued (status 3) are left out. No customer data.
SELECT
    P.id,
    P.brand_id,
    PB.name AS brand_name,
    P.model_name,
    P.reference,
    P.custom_offer,
    P.created_at,
    P.price_exc_tax,
    COALESCE(PS.product_available, 0) AS available,
    COALESCE(PS.descr_short, '') AS status_label,
    EXISTS (
        SELECT 1 FROM {PREFIX}productoptions AS PO
        WHERE PO.product_id = P.id AND PO.deleted_at IS NULL AND PO.disabled = 0
    ) AS has_options,
    (
        SELECT PPC.category_id
        FROM {PREFIX}products_productcategories AS PPC
        INNER JOIN {PREFIX}productcategories AS PC ON PC.id = PPC.category_id AND PC.deleted_at IS NULL
        WHERE PPC.product_id = P.id AND PPC.deleted_at IS NULL
        ORDER BY COALESCE(PPC.sort, 9999), PPC.category_id
        LIMIT 1
    ) AS category_id,
    (
        SELECT GROUP_CONCAT(PPC.category_id ORDER BY COALESCE(PPC.sort, 9999), PPC.category_id)
        FROM {PREFIX}products_productcategories AS PPC
        INNER JOIN {PREFIX}productcategories AS PC ON PC.id = PPC.category_id AND PC.deleted_at IS NULL
        WHERE PPC.product_id = P.id AND PPC.deleted_at IS NULL
    ) AS category_ids,
    (
        SELECT GROUP_CONCAT(CONCAT(PPC.category_id, ':', COALESCE(PPC.sort, 9999)) ORDER BY COALESCE(PPC.sort, 9999), PPC.category_id)
        FROM {PREFIX}products_productcategories AS PPC
        INNER JOIN {PREFIX}productcategories AS PC ON PC.id = PPC.category_id AND PC.deleted_at IS NULL
        WHERE PPC.product_id = P.id AND PPC.deleted_at IS NULL
    ) AS category_sorts
FROM {PREFIX}products AS P
INNER JOIN {PREFIX}productbrands AS PB ON PB.id = P.brand_id AND PB.deleted_at IS NULL
LEFT JOIN {PREFIX}productstatus AS PS ON PS.id = P.status_id AND PS.deleted_at IS NULL
WHERE P.deleted_at IS NULL
  AND P.status_id != 3
ORDER BY PB.name ASC, P.model_name ASC
