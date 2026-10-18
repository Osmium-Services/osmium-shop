SELECT h.changed_at, h.price_exc_tax_old, h.price_exc_tax_new, h.rrp_exc_tax_old, h.rrp_exc_tax_new, h.change_source, h.undone_at, u.username
FROM { PREFIX }product_price_history h
LEFT JOIN { PREFIX }users u ON h.changed_by = u.id
WHERE h.product_id = :product_id
ORDER BY h.changed_at DESC
