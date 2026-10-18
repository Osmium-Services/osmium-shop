SELECT COUNT(*) as count
FROM { PREFIX }products
WHERE status_id = :id
