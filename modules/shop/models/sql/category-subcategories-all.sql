-- All direct subcategories of :thisID, for the shop's category navigation tree.
-- Unlike category-subcategories.sql (which hides empty subcategories on a category
-- page to avoid dead-end tiles), a "browse by category" menu lists the full tree
-- whether or not products have been added yet.
SELECT
    PC.id,
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
ORDER BY
    PC.sort;
