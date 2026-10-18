SELECT
    PC.label,
    PC.title,
    PC.image,
    PCP.page_id
FROM
    { TABLE } AS PC
    INNER JOIN { PREFIX } productcategories_pages AS PCP ON PCP.productcategory_id = PC.id
WHERE
    PC.parent_id = :thisID
    AND PC.deleted_at IS NULL
    AND (
        -- Has products
        EXISTS (
            SELECT 1
            FROM { PREFIX } products_productcategories PPC
            WHERE PPC.category_id = PC.id
            AND PPC.deleted_at IS NULL
        )
        OR
        -- Has subcategories
        EXISTS (
            SELECT 1
            FROM { PREFIX } productcategories CHILD
            WHERE CHILD.parent_id = PC.id
            AND CHILD.deleted_at IS NULL
        )
    )
ORDER BY
    PC.sort;
