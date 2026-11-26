-- Products created, changed or deleted in the last 24 hours.
WHERE (P.created_at >= NOW() - INTERVAL 24 HOUR OR P.updated_at >= NOW() - INTERVAL 24 HOUR OR P.deleted_at >= NOW() - INTERVAL 24 HOUR)
