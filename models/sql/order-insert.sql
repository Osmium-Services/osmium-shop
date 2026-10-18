-- Create a pending order. Written before payment is attempted, never after:
-- an order row that only appears on success cannot record a payment that
-- succeeded while the browser died.
INSERT INTO {PREFIX}shop_orders (
    basket_id, status,
    customer_name, customer_email, customer_phone,
    billing_address1, billing_address2, billing_city, billing_county, billing_postcode, billing_country,
    delivery_name, delivery_address1, delivery_address2, delivery_city, delivery_county, delivery_postcode, delivery_country, delivery_zone,
    subtotal_exc_tax, shipping_amount, tax_amount, tax_rate_percent, total_inc_tax, currency,
    discount_id, discount_code, discount_amount,
    terms_accepted_at, ip_address, user_agent
) VALUES (
    :basket_id, 'pending',
    :customer_name, :customer_email, :customer_phone,
    :billing_address1, :billing_address2, :billing_city, :billing_county, :billing_postcode, :billing_country,
    :delivery_name, :delivery_address1, :delivery_address2, :delivery_city, :delivery_county, :delivery_postcode, :delivery_country, :delivery_zone,
    :subtotal_exc_tax, :shipping_amount, :tax_amount, :tax_rate_percent, :total_inc_tax, :currency,
    :discount_id, :discount_code, :discount_amount,
    :terms_accepted_at, :ip_address, :user_agent
)
