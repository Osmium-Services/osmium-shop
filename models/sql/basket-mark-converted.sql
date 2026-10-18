UPDATE {PREFIX}checkout_baskets
SET status = 'converted'
WHERE session_id = :session_id
  AND status = 'active'
