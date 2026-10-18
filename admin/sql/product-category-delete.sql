DELETE FROM { PREFIX }products_productcategories
WHERE product_id = :product_id AND category_id = :category_id
