SELECT
    D.id,
    D.name,
    D.type,
    D.code,
    D.value_type,
    D.value,
    D.free_delivery,
    D.starts_at,
    D.ends_at,
    D.usage_limit,
    D.usage_limit_per_customer,
    D.min_spend_inc_tax,
    D.times_used,
    D.active,
    D.created_at,
    (SELECT COUNT(*) FROM {PREFIX}discount_products WHERE discount_id = D.id) AS product_count,
    (SELECT COUNT(*) FROM {PREFIX}discount_categories WHERE discount_id = D.id) AS category_count,
    (SELECT COUNT(*) FROM {PREFIX}discount_brands WHERE discount_id = D.id) AS brand_count,
    COALESCE(R.orders_count, 0) AS orders_count,
    COALESCE(R.discount_given, 0) AS discount_given
FROM {PREFIX}discounts AS D
LEFT JOIN (
    -- Paid orders only. A line carries its own discount; an order also names its sitewide or coupon discount, which is
    -- the only trace a free-delivery coupon leaves. COUNT(DISTINCT) counts an order once however it matched
    SELECT discount_id, COUNT(DISTINCT order_id) AS orders_count, SUM(discount_amount) AS discount_given
    FROM (
        SELECT I.discount_id, O.id AS order_id, I.discount_amount
        FROM {PREFIX}shop_order_items AS I
        INNER JOIN {PREFIX}shop_orders AS O ON O.id = I.order_id
        WHERE I.discount_id IS NOT NULL AND O.status IN ('paid', 'dispatched')
        UNION ALL
        SELECT O.discount_id, O.id, 0
        FROM {PREFIX}shop_orders AS O
        WHERE O.discount_id IS NOT NULL AND O.status IN ('paid', 'dispatched')
    ) AS U
    GROUP BY discount_id
) AS R ON R.discount_id = D.id
WHERE D.deleted_at IS NULL
ORDER BY D.created_at DESC
