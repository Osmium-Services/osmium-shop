SELECT
    P.id
FROM
    { TABLE } AS P
WHERE
    P.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
    AND P.deleted_at IS NULL
ORDER BY
    P.created_at DESC
LIMIT
    :limit;
