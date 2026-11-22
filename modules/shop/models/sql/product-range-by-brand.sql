SELECT
    GROUP_CONCAT(
        CAST(P.id as CHAR)
        ORDER BY
            { SORT_COLUMN }
    ) AS products
FROM
    { TABLE } AS P
    INNER JOIN { PREFIX } productstatus AS PS ON PS.id = P.status_id
WHERE
    P.brand_id = :brandID
    AND P.deleted_at IS NULL
    AND P.status_id != 3
    {STOCK_FILTER};
