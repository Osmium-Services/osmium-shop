SELECT
    h.changed_at,
    h.changed_by,
    u.username,
    COUNT(*) as product_count
FROM { PREFIX }product_price_history h
LEFT JOIN { PREFIX }users u ON h.changed_by = u.id
WHERE h.change_source = 'import' AND h.undone_at IS NULL
    AND (:product_id = 0 OR h.product_id = :product_id)
GROUP BY h.changed_at, h.changed_by
ORDER BY h.changed_at DESC
