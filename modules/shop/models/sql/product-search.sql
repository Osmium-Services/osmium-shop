-- Product search. {RELEVANCE} and {WHERE} are built by ShopSearchService
-- from its field weights and bound parameters (:exactN, :startsN, :wordN, :searchN).
SELECT DISTINCT
    P.id,
    P.model_name,
    P.reference,
    P.price_exc_tax,
    P.rrp_exc_tax,
    PB.name AS brand_name,
    PS.product_available,
    PS.descr_short,
    PP.page_id,
    P.created_at,
    ({RELEVANCE}) AS relevance
FROM
    {TABLE} AS P
    INNER JOIN {PREFIX}productbrands AS PB ON PB.id = P.brand_id
    INNER JOIN {PREFIX}productstatus AS PS ON PS.id = P.status_id
    INNER JOIN {PREFIX}products_pages AS PP ON PP.product_id = P.id
WHERE
    P.deleted_at IS NULL
    AND PS.deleted_at IS NULL
    AND PB.deleted_at IS NULL
    AND NOT PP.is_qr
    AND P.status_id NOT IN (3, 4)
    AND {WHERE}
ORDER BY
    PS.product_available DESC,
    CASE WHEN P.reference LIKE '%(C00%' THEN 1 ELSE 0 END,
    relevance DESC,
    P.created_at DESC
LIMIT {LIMIT}
