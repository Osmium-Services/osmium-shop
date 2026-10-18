-- Get the first category's page_id for a product
SELECT PC.page_id
FROM {PREFIX}products_productcategories PPC
JOIN {PREFIX}productcategories PC ON PC.id = PPC.category_id
WHERE PPC.product_id = :product_id
LIMIT 1
