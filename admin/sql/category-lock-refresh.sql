UPDATE { PREFIX }productcategories_lock
SET created_at = NOW()
WHERE user_id = :user_id
