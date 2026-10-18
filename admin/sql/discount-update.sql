UPDATE {PREFIX}discounts
SET name = :name,
    type = :type,
    code = :code,
    value_type = :value_type,
    value = :value,
    starts_at = :starts_at,
    ends_at = :ends_at,
    usage_limit = :usage_limit,
    usage_limit_per_customer = :usage_limit_per_customer,
    min_spend_inc_tax = :min_spend_inc_tax,
    active = :active
WHERE id = :id
