-- Top-level product categories, for the shop landing page and the home page category cards.
--
-- Unlike category-subcategories.sql this does NOT require a category to
-- already have products or child categories. The range is small and every
-- category is intended to be shown, so an empty one should still appear
-- rather than silently vanish from the listing.
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
    PC.parent_id IS NULL
    AND PC.deleted_at IS NULL
ORDER BY
    PC.sort;
