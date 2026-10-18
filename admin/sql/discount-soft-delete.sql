UPDATE {PREFIX}discounts
SET deleted_at = NOW()
WHERE id = :id
