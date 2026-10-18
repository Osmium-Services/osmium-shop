SELECT
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_count,
    SUM(CASE WHEN status = 'active' AND updated_at < DATE_SUB(NOW(), INTERVAL :hours HOUR) THEN 1 ELSE 0 END) as abandoned_now,
    SUM(CASE WHEN status = 'converted' AND updated_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) as converted_24h
FROM { PREFIX } checkout_baskets
