SELECT
    D.id,
    D.name,
    D.type,
    D.code,
    D.value_type,
    D.value,
    D.starts_at,
    D.ends_at,
    D.usage_limit,
    D.usage_limit_per_customer,
    D.min_spend_inc_tax,
    D.times_used,
    D.active,
    D.created_at,
    COUNT(DP.product_id) as product_count
FROM {PREFIX}discounts AS D
LEFT JOIN {PREFIX}discount_products AS DP ON DP.discount_id = D.id
WHERE D.deleted_at IS NULL
GROUP BY D.id
ORDER BY D.created_at DESC
