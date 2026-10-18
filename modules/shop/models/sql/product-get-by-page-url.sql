SELECT
    P.*,
    B.name AS brand_name,
    S.descr_short AS status_descr,
    G.url AS page_url
FROM
    { PREFIX }products AS P
    INNER JOIN { PREFIX }productbrands AS B ON B.id = P.brand_id
    INNER JOIN { PREFIX }productstatus AS S ON S.id = P.status_id
    INNER JOIN { PREFIX }products_pages AS PP ON PP.product_id = P.id AND PP.is_qr = 0
    INNER JOIN { PREFIX }pages AS G ON G.id = PP.page_id
WHERE
    G.url = :page_url
    AND P.deleted_at IS NULL
LIMIT 1
