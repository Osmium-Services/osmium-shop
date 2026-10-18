SELECT DISTINCT p.id, b.name AS brand_name, p.model_name
FROM { PREFIX }products p
INNER JOIN { PREFIX }productoptiontypes ot ON ot.product_id = p.id AND ot.deleted_at IS NULL
LEFT JOIN { PREFIX }productbrands b ON b.id = p.brand_id
WHERE p.deleted_at IS NULL
ORDER BY b.name, p.model_name
