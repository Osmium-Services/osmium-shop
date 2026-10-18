SELECT page_id
FROM { PREFIX }products_pages
WHERE product_id = :id
AND is_qr = 0
