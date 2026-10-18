SELECT id, model_name
FROM {PREFIX}products
WHERE deleted_at IS NULL
ORDER BY model_name ASC
