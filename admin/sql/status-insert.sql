INSERT INTO { PREFIX }productstatus (descr_short, descr_long, product_available, restocking, schema_ref, list, enabled, created_at)
VALUES (:descr_short, :descr_long, :product_available, :restocking, :schema_ref, :list, :enabled, NOW())
