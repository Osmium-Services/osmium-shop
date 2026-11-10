-- Number of orders for the admin list pager.
-- {STATUS_FILTER} is an inlined clause built from a fixed whitelist in
-- OrdersController - never from raw input.
SELECT COUNT(*) AS total
FROM {PREFIX}shop_orders AS o
{STATUS_FILTER}
