SELECT
    P.*,
    PPC.category_id,
    PS.product_available,
    PS.descr_short,
    PS.descr_long,
    PS.schema_ref,
    PB.name as brand_name,
    PB.logo AS brand_logo,
    BP.url AS brand_page_url,
    PP.page_id
FROM
    { TABLE } AS P
    INNER JOIN { PREFIX } productstatus AS PS ON PS.id = P.status_id
    INNER JOIN { PREFIX } productbrands AS PB ON PB.id = P.brand_id
    LEFT JOIN { PREFIX } pages AS BP ON BP.id = PB.page_id AND BP.deleted_at IS NULL
    INNER JOIN { PREFIX } products_pages AS PP ON PP.product_id = P.id
    LEFT JOIN { PREFIX } products_productcategories AS PPC ON PPC.product_id = P.id
WHERE
    P.id = :productID
    AND NOT PP.is_qr
    AND PS.deleted_at IS NULL
    AND PB.deleted_at IS NULL;
