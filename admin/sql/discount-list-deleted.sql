SELECT
    D.id,
    D.name,
    D.type,
    D.deleted_at
FROM {PREFIX}discounts AS D
WHERE D.deleted_at IS NOT NULL
ORDER BY D.deleted_at DESC
