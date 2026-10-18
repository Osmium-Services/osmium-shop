INSERT INTO { PREFIX }product_price_history
(product_id, price_exc_tax_old, price_exc_tax_new, rrp_exc_tax_old, rrp_exc_tax_new, changed_at, changed_by, change_source)
VALUES (:product_id, :old_price, :new_price, :old_rrp, :new_rrp, NOW(), :user_id, 'manual')
