-- Orderable products assigned to any of a set of categories (a category and the ones beneath it), each listed
-- once, in the order given by the sort column. Sibling of product-range-by-category.sql, which takes one category.
SELECT
    GROUP_CONCAT(
        DISTINCT CAST(P.id as CHAR)
        ORDER BY
            { SORT_COLUMN }
    ) AS products
FROM
    { TABLE } AS P
    INNER JOIN { PREFIX } products_productcategories AS PC ON P.id = PC.product_id
    INNER JOIN { PREFIX } productstatus AS PS ON PS.id = P.status_id
WHERE
    PC.deleted_at IS NULL
    AND FIND_IN_SET(PC.category_id, :categoryIDs)
    AND P.deleted_at IS NULL
    AND P.status_id != 3
    {STOCK_FILTER};
