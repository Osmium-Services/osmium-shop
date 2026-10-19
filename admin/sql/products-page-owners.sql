SELECT pp.page_id, pp.product_id, p.model_name
FROM { PREFIX }products_pages pp
JOIN { PREFIX }products p ON p.id = pp.product_id
WHERE p.deleted_at IS NULL
