INSERT INTO {PREFIX}discounts (
    name, type, code, value_type, value, free_delivery, starts_at, ends_at, usage_limit, usage_limit_per_customer, min_spend_inc_tax, active, created_at
) VALUES (
    :name, :type, :code, :value_type, :value, :free_delivery, :starts_at, :ends_at, :usage_limit, :usage_limit_per_customer, :min_spend_inc_tax, :active, NOW()
)
