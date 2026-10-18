SELECT
    COUNT(*) AS total
FROM
    { TABLE } AS P
WHERE
    P.deleted_at IS NULL;
