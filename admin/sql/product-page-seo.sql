-- A product's own page (not its QR page) with the address and the title and description search engines see.
SELECT PG.url, PG.title, PG.description, PG.robots
FROM {PREFIX}products_pages AS PP
INNER JOIN {PREFIX}pages AS PG ON PG.id = PP.page_id AND PG.deleted_at IS NULL
WHERE PP.product_id = :product_id
  AND PP.is_qr = 0
  AND PP.deleted_at IS NULL
LIMIT 1
