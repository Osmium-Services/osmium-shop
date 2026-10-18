SELECT p.*, b.name as brand_name
FROM { PREFIX }products p
LEFT JOIN { PREFIX }productbrands b ON p.brand_id = b.id
WHERE p.id = :id
