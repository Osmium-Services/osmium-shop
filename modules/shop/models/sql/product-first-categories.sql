SELECT
    PC.product_id,
    C.id AS category_id,
    C.label
FROM
    { PREFIX } products_productcategories AS PC
    INNER JOIN { PREFIX } productcategories AS C ON C.id = PC.category_id
WHERE
    PC.deleted_at IS NULL
    AND FIND_IN_SET(PC.product_id, :productIDs)
ORDER BY
    C.id;
