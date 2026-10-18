SELECT COUNT(*) as count
FROM { PREFIX }products
WHERE brand_id = :id
