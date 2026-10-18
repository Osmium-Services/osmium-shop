SELECT
    B.id,
    B.session_id,
    B.status,
    B.created_at,
    B.updated_at,
    COUNT(BI.id) as item_count,
    COALESCE(SUM(BI.quantity), 0) as total_items,
    COALESCE(SUM(BI.quantity * BI.unit_price_exc_tax), 0) as total_value
FROM { PREFIX } checkout_baskets AS B
LEFT JOIN { PREFIX } checkout_basket_items AS BI ON BI.basket_id = B.id
WHERE B.status = 'active' AND B.updated_at < DATE_SUB(NOW(), INTERVAL :hours HOUR)
GROUP BY B.id
ORDER BY B.updated_at DESC
