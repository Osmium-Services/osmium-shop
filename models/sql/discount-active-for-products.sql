SELECT dp.product_id, d.id, d.name, d.value_type, d.value
FROM {PREFIX}discount_products dp
INNER JOIN {PREFIX}discounts d ON d.id = dp.discount_id
WHERE
    d.type = 'product'
    AND d.active = 1
    AND d.deleted_at IS NULL
    AND (d.starts_at IS NULL OR d.starts_at <= NOW())
    AND (d.ends_at IS NULL OR d.ends_at >= NOW())
    AND dp.product_id IN ({PLACEHOLDERS})
