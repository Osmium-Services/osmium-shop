-- The newest orders with no customer details: reference, status, money and dates only.
SELECT
    order_ref,
    status,
    total_inc_tax,
    currency,
    payment_provider,
    created_at,
    paid_at,
    dispatched_at
FROM {PREFIX}shop_orders
WHERE is_test_payment = 0
ORDER BY created_at DESC, id DESC
LIMIT :limit
