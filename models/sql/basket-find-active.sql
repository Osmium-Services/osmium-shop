-- Active basket for this session, if one exists.
SELECT id, session_id, status, created_at, updated_at
FROM {PREFIX}checkout_baskets
WHERE session_id = :session_id
  AND status = 'active'
ORDER BY id DESC
LIMIT 1
