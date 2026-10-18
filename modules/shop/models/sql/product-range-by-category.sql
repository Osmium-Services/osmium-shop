SELECT
    GROUP_CONCAT(
        CAST(P.id as CHAR)
        ORDER BY
            { SORT_COLUMN }
    ) AS products
FROM
    { TABLE } AS P
    INNER JOIN { PREFIX } products_productcategories AS PC ON P.id = PC.product_id
WHERE
    PC.deleted_at IS NULL
    AND PC.category_id IN (:categoryIDs)
    AND P.deleted_at IS NULL
    AND P.status_id != 3;
