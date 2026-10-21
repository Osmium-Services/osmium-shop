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
    (SELECT COUNT(*) FROM {PREFIX}discount_products WHERE discount_id = D.id) AS product_count,
    (SELECT COUNT(*) FROM {PREFIX}discount_categories WHERE discount_id = D.id) AS category_count,
    (SELECT COUNT(*) FROM {PREFIX}discount_brands WHERE discount_id = D.id) AS brand_count
FROM {PREFIX}discounts AS D
WHERE D.deleted_at IS NULL
ORDER BY D.created_at DESC
