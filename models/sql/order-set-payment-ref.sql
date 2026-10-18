-- Record the provider's order id against our pending order.
-- Written before the customer is sent to pay, so a capture can always be
-- traced back to the order it belongs to.
UPDATE {PREFIX}shop_orders
SET payment_provider = :payment_provider,
    payment_order_ref = :payment_order_ref,
    is_test_payment = :is_test_payment
WHERE id = :id
  AND status = 'pending'
