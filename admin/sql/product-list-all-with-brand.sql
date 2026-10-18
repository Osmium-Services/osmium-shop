SELECT p.id, b.name as brand_name, p.model_name
FROM { PREFIX }products p
LEFT JOIN { PREFIX }productbrands b ON p.brand_id = b.id
WHERE p.deleted_at IS NULL
ORDER BY b.name, p.model_name
