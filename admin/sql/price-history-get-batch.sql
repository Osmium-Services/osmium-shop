SELECT id, product_id, price_exc_tax_old, rrp_exc_tax_old
FROM { PREFIX }product_price_history
WHERE changed_at = :timestamp AND change_source = 'import'
