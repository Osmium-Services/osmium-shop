SELECT
    h.id,
    h.product_id,
    h.price_exc_tax_old,
    h.price_exc_tax_new,
    h.rrp_exc_tax_old,
    h.rrp_exc_tax_new,
    h.changed_at,
    h.changed_by,
    u.username,
    b.name AS brand_name,
    p.model_name
FROM { PREFIX }product_price_history h
LEFT JOIN { PREFIX }users u ON h.changed_by = u.id
LEFT JOIN { PREFIX }products p ON h.product_id = p.id
LEFT JOIN { PREFIX }productbrands b ON p.brand_id = b.id
WHERE h.change_source = 'manual' AND h.undone_at IS NULL
    AND (:product_id = 0 OR h.product_id = :product_id)
ORDER BY h.changed_at DESC
