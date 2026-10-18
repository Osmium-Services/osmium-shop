UPDATE {PREFIX}products SET restock_at = :restock_at, updated_at = NOW() WHERE id = :id AND deleted_at IS NULL
