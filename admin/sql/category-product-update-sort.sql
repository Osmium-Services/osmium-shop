UPDATE { PREFIX }products_productcategories
SET sort = :sort
WHERE product_id = :product_id AND category_id = :category_id
