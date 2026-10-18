SELECT COALESCE(SUM(BI.quantity * BI.unit_price_exc_tax), 0) as total_value
FROM { PREFIX } checkout_baskets AS B
JOIN { PREFIX } checkout_basket_items AS BI ON BI.basket_id = B.id
WHERE B.status = 'converted'
AND B.updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
