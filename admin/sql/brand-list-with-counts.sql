SELECT
    B.id,
    B.name,
    B.page_url,
    G.url AS brand_page_url, -- The brand's own page, if it has one: what the admin list shows
    B.info_external,
    IF(B.logo IS NULL OR B.logo = '', NULL, CONCAT('/assets/images/shop/brands/', B.logo)) AS logo_url,
    -- Just the site's name for the list, such as www.coca-cola.com, so the column reads without printing the whole link
    NULLIF(SUBSTRING_INDEX(SUBSTRING_INDEX(B.info_external, '/', 3), '/', -1), '') AS info_host,
    B.created_at,
    COUNT(P.id) as product_count
FROM { PREFIX }productbrands AS B
LEFT JOIN { PREFIX }products AS P ON P.brand_id = B.id AND P.deleted_at IS NULL
LEFT JOIN { PREFIX }pages AS G ON G.id = B.page_id AND G.deleted_at IS NULL
WHERE B.deleted_at IS NULL
GROUP BY B.id
ORDER BY B.name ASC
