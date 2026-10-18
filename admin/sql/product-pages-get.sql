SELECT page_id, is_qr
FROM { PREFIX }products_pages
WHERE product_id = :product_id AND deleted_at IS NULL
