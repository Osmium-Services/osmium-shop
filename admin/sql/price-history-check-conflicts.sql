SELECT DISTINCT
    h.product_id,
    b.name AS brand_name,
    p.model_name
FROM
    { PREFIX }product_price_history h
    LEFT JOIN { PREFIX }products p ON h.product_id = p.id
    LEFT JOIN { PREFIX }productbrands b ON p.brand_id = b.id
WHERE
    h.product_id IN ({PLACEHOLDERS})
    AND h.changed_at > ?
    AND h.undone_at IS NULL;
