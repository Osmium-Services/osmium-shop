SELECT p.id, p.reference, p.model_name, p.price_exc_tax, p.packed_weight
FROM {PREFIX}products AS p
INNER JOIN {PREFIX}productstatus AS s ON s.id = p.status_id
  AND s.product_available = 1
  AND s.deleted_at IS NULL
WHERE p.id = :id
  AND p.deleted_at IS NULL
LIMIT 1
