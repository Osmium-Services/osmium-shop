-- Order counts and values per status for today, the last 7 days and the last
-- 30 days, by created date. Test payments are left out. No customer data.
SELECT
    status,
    SUM(created_at >= CURDATE()) AS today_count,
    COALESCE(SUM(CASE WHEN created_at >= CURDATE() THEN total_inc_tax END), 0) AS today_value,
    SUM(created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) AS week_count,
    COALESCE(SUM(CASE WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) THEN total_inc_tax END), 0) AS week_value,
    COUNT(*) AS month_count,
    COALESCE(SUM(total_inc_tax), 0) AS month_value
FROM {PREFIX}shop_orders
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
  AND is_test_payment = 0
GROUP BY status
