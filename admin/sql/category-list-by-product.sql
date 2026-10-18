SELECT pc.id, pc.label, pc.title
FROM { PREFIX }productcategories pc
INNER JOIN { PREFIX }products_productcategories ppc ON pc.id = ppc.category_id
WHERE ppc.product_id = :product_id
ORDER BY pc.label
