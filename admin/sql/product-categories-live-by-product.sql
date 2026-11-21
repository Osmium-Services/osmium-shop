-- The live categories a product sits in, by label.
SELECT PC.id, PC.label
FROM {PREFIX}productcategories AS PC
INNER JOIN {PREFIX}products_productcategories AS PPC ON PPC.category_id = PC.id AND PPC.deleted_at IS NULL
WHERE PPC.product_id = :product_id
  AND PC.deleted_at IS NULL
ORDER BY PC.label
