SELECT
    pc.id,
    pc.parent_id,
    pc.label,
    pc.title,
    pc.subtitle,
    pc.description,
    pc.image,
    pc.social_image,
    pc.sort,
    COUNT(p.id) as product_count
FROM { PREFIX }productcategories pc
LEFT JOIN { PREFIX }products_productcategories ppc ON pc.id = ppc.category_id
LEFT JOIN { PREFIX }products p ON p.id = ppc.product_id AND p.deleted_at IS NULL
WHERE pc.deleted_at IS NULL
GROUP BY pc.id
ORDER BY pc.sort, pc.label
