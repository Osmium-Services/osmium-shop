SELECT
    P.id,
    P.reference,
    P.model_name,
    P.price_exc_tax,
    P.created_at,
    P.updated_at,
    PB.name as brand_name,
    PS.descr_long as status_name,
    PS.product_available as available,
    PP.page_id,
    MAX(CASE WHEN PPC.product_id IS NOT NULL THEN 1 ELSE 0 END) as has_category
FROM { PREFIX }products AS P
LEFT JOIN { PREFIX }productbrands AS PB ON PB.id = P.brand_id
LEFT JOIN { PREFIX }productstatus AS PS ON PS.id = P.status_id
LEFT JOIN { PREFIX }products_pages AS PP ON PP.product_id = P.id AND PP.is_qr = 0
LEFT JOIN { PREFIX }products_productcategories AS PPC ON PPC.product_id = P.id AND PPC.deleted_at IS NULL
WHERE P.deleted_at IS NULL
  AND (P.created_at >= NOW() - INTERVAL 24 HOUR OR P.updated_at >= NOW() - INTERVAL 24 HOUR)
GROUP BY P.id, PP.page_id
ORDER BY GREATEST(COALESCE(P.updated_at, P.created_at), P.created_at) DESC, P.id DESC
LIMIT 10
