SELECT id
FROM {PREFIX}discounts
WHERE code = :code
LIMIT 1
