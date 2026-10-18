SELECT productcategory_id, page_id
FROM { PREFIX }productcategories_pages
WHERE deleted_at IS NULL
