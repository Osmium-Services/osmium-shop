SELECT id
FROM { PREFIX }productcategories_lock
WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)
