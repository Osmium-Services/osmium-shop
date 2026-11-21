-- One order by its reference with no customer details: status, payment, money, delivery country and dates only.
-- Columns are named one by one on purpose, so a new column on the table can never leak through.
SELECT
    id,
    order_ref,
    status,
    is_test_payment,
    payment_provider,
    payment_method,
    payment_order_ref,
    payment_capture_ref,
    currency,
    subtotal_exc_tax,
    shipping_amount,
    tax_amount,
    tax_rate_percent,
    discount_code,
    discount_amount,
    total_inc_tax,
    delivery_zone,
    delivery_country,
    tracking_carrier,
    tracking_ref,
    terms_accepted_at,
    xero_synced_at,
    created_at,
    paid_at,
    dispatched_at
FROM {PREFIX}shop_orders
WHERE order_ref = :order_ref
LIMIT 1
