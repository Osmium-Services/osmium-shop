-- Record that a refund was issued in the payment provider.
--
-- Recorded, never executed: the refund itself happens in Stripe/PayPal. Issuing
-- money back from admin is a meaningful security surface and is deliberately
-- not built.
UPDATE {PREFIX}shop_orders
SET status = 'refunded'
WHERE id = :id
  AND status IN ('paid', 'dispatched')
