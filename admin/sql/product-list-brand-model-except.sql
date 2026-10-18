SELECT b.name AS brand_name, p.model_name
FROM { PREFIX }products p
LEFT JOIN { PREFIX }productbrands b ON p.brand_id = b.id
WHERE p.id != :id
