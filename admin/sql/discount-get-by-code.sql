SELECT id
FROM {PREFIX}discounts
WHERE code = :code
    AND deleted_at IS NULL
LIMIT 1
