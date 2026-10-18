SELECT id, name, value_type, value, usage_limit, usage_limit_per_customer, min_spend_inc_tax, times_used
FROM {PREFIX}discounts
WHERE
    type = 'coupon'
    AND code = :code
    AND active = 1
    AND deleted_at IS NULL
    AND (starts_at IS NULL OR starts_at <= NOW())
    AND (ends_at IS NULL OR ends_at >= NOW())
    AND (usage_limit IS NULL OR times_used < usage_limit)
LIMIT 1
