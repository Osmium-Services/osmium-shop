-- Category ids that have at least one orderable product assigned directly
-- (not via a subcategory). Used by the category tree to grey out links for
-- categories that would land on an empty page.
SELECT DISTINCT
    PPC.category_id
FROM
    { PREFIX } products_productcategories AS PPC
    INNER JOIN { PREFIX } products AS P ON P.id = PPC.product_id
WHERE
    PPC.deleted_at IS NULL
    AND P.deleted_at IS NULL
    AND P.status_id != 3;
