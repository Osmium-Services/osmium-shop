SELECT p.id, b.name AS brand_name, p.model_name
FROM { PREFIX }products p
LEFT JOIN { PREFIX }productbrands b ON b.id = p.brand_id
WHERE p.deleted_at IS NULL
  AND p.techspec IS NOT NULL
  AND p.techspec != ''
ORDER BY b.name, p.model_name
