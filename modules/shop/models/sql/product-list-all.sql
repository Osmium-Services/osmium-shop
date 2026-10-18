SELECT
    P.id
FROM
    { TABLE } AS P
WHERE
    P.deleted_at IS NULL
ORDER BY
    P.created_at DESC;
