SELECT
    S.id,
    S.descr_short,
    S.descr_long,
    S.product_available,
    S.restocking,
    S.schema_ref,
    S.list,
    S.enabled,
    S.created_at,
    COUNT(P.id) as product_count
FROM { PREFIX }productstatus AS S
LEFT JOIN { PREFIX }products AS P ON P.status_id = S.id AND P.deleted_at IS NULL
WHERE S.deleted_at IS NULL
GROUP BY S.id
ORDER BY S.id ASC
