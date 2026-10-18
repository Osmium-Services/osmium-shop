-- Orders for the admin list, newest first.
-- {STATUS_FILTER} is an inlined clause built from a fixed whitelist in
-- OrdersController - never from raw input.
SELECT
    o.id,
    o.order_ref,
    o.invoice_number,
    o.status,
    o.customer_name,
    o.customer_email,
    o.delivery_postcode,
    o.delivery_zone,
    o.total_inc_tax,
    o.currency,
    o.payment_provider,
    o.is_test_payment,
    o.paid_at,
    o.dispatched_at,
    o.created_at,
    COUNT(oi.id) AS line_count,
    COALESCE(SUM(oi.quantity), 0) AS unit_count
FROM {PREFIX}shop_orders AS o
LEFT JOIN {PREFIX}shop_order_items AS oi ON oi.order_id = o.id
{STATUS_FILTER}
GROUP BY o.id
ORDER BY o.created_at DESC, o.id DESC
LIMIT 200
