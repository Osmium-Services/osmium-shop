UPDATE { PREFIX }productstatus
SET descr_short = :descr_short,
    descr_long = :descr_long,
    product_available = :product_available,
    restocking = :restocking,
    schema_ref = :schema_ref,
    list = :list,
    enabled = :enabled
WHERE id = :id
