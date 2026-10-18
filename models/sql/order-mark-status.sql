-- Move an order to a terminal state that is not payment (failed, cancelled,
-- refunded). Never used to mark an order paid - that has its own guarded
-- statement.
--
-- The status IN (...) guard stops a late or out-of-order provider event from
-- undoing a payment: a 'failed' event arriving after the successful capture
-- updates zero rows instead of turning a paid order into a failed one.
UPDATE {PREFIX}shop_orders
SET status = :status
WHERE id = :id
  AND status IN (:from_a, :from_b)
