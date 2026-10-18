UPDATE { PREFIX }products
SET deleted_at = NOW(), status_id = 4
WHERE id = :id
