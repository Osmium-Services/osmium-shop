SELECT id, product_id, price_exc_tax_old, rrp_exc_tax_old, changed_at
FROM { PREFIX }product_price_history
WHERE id = :id AND change_source = 'manual'
