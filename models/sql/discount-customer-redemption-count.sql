SELECT COUNT(*) AS redemption_count
FROM {PREFIX}discount_redemptions
WHERE discount_id = :discount_id
    AND customer_email = :customer_email
