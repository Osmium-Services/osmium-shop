SELECT user_id, user_name, created_at
FROM { PREFIX }productcategories_lock
ORDER BY created_at DESC
LIMIT 1
