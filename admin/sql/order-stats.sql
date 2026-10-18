-- Counts for the orders dashboard strip.
-- 'Awaiting dispatch' is the number that matters day to day: orders paid for
-- and not yet sent.
SELECT
    COUNT(*) AS total_count,
    SUM(status = 'pending') AS pending_count,
    SUM(status = 'paid') AS awaiting_dispatch_count,
    SUM(status = 'dispatched') AS dispatched_count,
    SUM(status = 'refunded') AS refunded_count,
    SUM(status IN ('paid', 'dispatched') AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS paid_30d_count,
    COALESCE(SUM(CASE WHEN status IN ('paid', 'dispatched') AND paid_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN total_inc_tax END), 0) AS paid_30d_value
FROM {PREFIX}shop_orders
