-- The live product statuses a tool can offer to pick from, with what each one means for the storefront.
SELECT id, descr_short, descr_long, product_available, restocking, enabled
FROM {PREFIX}productstatus
WHERE deleted_at IS NULL
ORDER BY id
