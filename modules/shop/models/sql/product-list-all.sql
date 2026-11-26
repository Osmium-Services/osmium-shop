SELECT
    P.id
FROM
    {TABLE} AS P
    INNER JOIN {PREFIX} productstatus AS PS ON PS.id = P.status_id
WHERE
    P.deleted_at IS NULL
    AND P.is_published = 1
    {STOCK_FILTER}
ORDER BY
    {SORT_COLUMN};
