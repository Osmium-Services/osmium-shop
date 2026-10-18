SELECT id
FROM { PREFIX }productcategories_lock
WHERE user_id = :user_id
LIMIT 1
