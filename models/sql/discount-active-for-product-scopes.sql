SELECT p.id AS product_id, d.id, d.name, d.value_type, d.value
FROM {PREFIX}products p
INNER JOIN {PREFIX}discounts d ON d.type = 'product'
WHERE
    p.id IN ({PLACEHOLDERS})
    AND d.active = 1
    AND d.deleted_at IS NULL
    AND (d.starts_at IS NULL OR d.starts_at <= NOW())
    AND (d.ends_at IS NULL OR d.ends_at >= NOW())
    AND (
        EXISTS (SELECT 1 FROM {PREFIX}discount_brands db WHERE db.discount_id = d.id AND db.brand_id = p.brand_id)
        OR EXISTS (
            SELECT 1
            FROM {PREFIX}discount_categories dc
            INNER JOIN {PREFIX}products_productcategories pc ON pc.category_id = dc.category_id
            WHERE dc.discount_id = d.id AND pc.product_id = p.id AND pc.deleted_at IS NULL
        )
    )
