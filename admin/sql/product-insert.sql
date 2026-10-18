INSERT INTO { PREFIX }products (
    brand_id,
    model_name,
    reference,
    barcode,
    status_id,
    price_exc_tax,
    rrp_exc_tax,
    descr,
    descr2,
    descr3,
    created_at
) VALUES (
    :brand_id,
    :model_name,
    :reference,
    :barcode,
    :status_id,
    :price_exc_tax,
    :rrp_exc_tax,
    :descr,
    :descr2,
    :descr3,
    NOW()
)
