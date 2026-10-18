INSERT INTO { PREFIX }products_productcategories (product_id, category_id)
SELECT :new_id, category_id
FROM { PREFIX }products_productcategories
WHERE product_id = :old_id
