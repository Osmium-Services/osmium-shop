-- Find an order by the payment provider's own reference.
-- The webhook knows nothing about our ids, only theirs.
SELECT *
FROM {PREFIX}shop_orders
WHERE payment_order_ref = :payment_order_ref
LIMIT 1
