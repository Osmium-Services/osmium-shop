SELECT id, name, value_type, value
FROM {PREFIX}discounts
WHERE
    type = 'sitewide'
    AND active = 1
    AND deleted_at IS NULL
    AND (starts_at IS NULL OR starts_at <= NOW())
    AND (ends_at IS NULL OR ends_at >= NOW())
ORDER BY value DESC
LIMIT 1
