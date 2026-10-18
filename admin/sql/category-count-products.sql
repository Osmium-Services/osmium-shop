SELECT COUNT(*) as product_count
FROM { PREFIX }products_productcategories ppc
INNER JOIN { PREFIX }products p ON p.id = ppc.product_id AND p.deleted_at IS NULL
WHERE ppc.category_id = :id
