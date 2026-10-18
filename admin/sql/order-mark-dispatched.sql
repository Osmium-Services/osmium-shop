-- Mark an order dispatched with its carrier and tracking reference.
-- Guarded on 'paid' so an unpaid or refunded order cannot be marked sent.
UPDATE {PREFIX}shop_orders
SET status = 'dispatched',
    dispatched_at = NOW(),
    tracking_carrier = :tracking_carrier,
    tracking_ref = :tracking_ref
WHERE id = :id
  AND status = 'paid'
