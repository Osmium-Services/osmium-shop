UPDATE {PREFIX}discounts
SET deleted_at = NULL
WHERE id = :id
