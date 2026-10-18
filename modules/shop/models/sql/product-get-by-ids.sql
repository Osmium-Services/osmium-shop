SELECT
    P.*,
    PPC.category_id,
    PS.product_available,
    PS.descr_short,
    PS.descr_long,
    PS.schema_ref,
    PB.name as brand_name,
    PP.page_id,
    EXISTS (
        SELECT 1 FROM { PREFIX } productoptiontypes AS POT
        WHERE POT.product_id = P.id AND POT.deleted_at IS NULL
    ) AS has_options
FROM
    { TABLE } AS P
    INNER JOIN { PREFIX } productstatus AS PS ON PS.id = P.status_id
    INNER JOIN { PREFIX } productbrands AS PB ON PB.id = P.brand_id
    INNER JOIN { PREFIX } products_pages AS PP ON PP.product_id = P.id
    LEFT JOIN { PREFIX } products_productcategories AS PPC ON PPC.product_id = P.id
WHERE
    P.id IN (:productIDs)
    AND NOT PP.is_qr
    AND PS.deleted_at IS NULL
    AND PB.deleted_at IS NULL;
