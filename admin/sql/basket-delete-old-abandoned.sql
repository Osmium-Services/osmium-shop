DELETE FROM { PREFIX } checkout_baskets
WHERE status = 'active'
AND updated_at < DATE_SUB(NOW(), INTERVAL :days DAY)
