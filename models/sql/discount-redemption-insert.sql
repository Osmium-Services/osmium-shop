INSERT INTO {PREFIX}discount_redemptions (discount_id, order_id, customer_email, used_at)
VALUES (:discount_id, :order_id, :customer_email, NOW())
