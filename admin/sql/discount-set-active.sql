UPDATE {PREFIX}discounts
SET active = :active
WHERE id = :id AND deleted_at IS NULL
