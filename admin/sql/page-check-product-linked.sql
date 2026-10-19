SELECT p.model_name
FROM { PREFIX }products_pages pp
JOIN { PREFIX }products p ON p.id = pp.product_id
WHERE pp.page_id = :page_id
