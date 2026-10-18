SELECT page_id
FROM { PREFIX }productcategories_pages
WHERE productcategory_id = :category_id
AND deleted_at IS NULL
