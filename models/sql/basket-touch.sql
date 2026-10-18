UPDATE {PREFIX}checkout_baskets
SET updated_at = NOW()
WHERE id = :id
