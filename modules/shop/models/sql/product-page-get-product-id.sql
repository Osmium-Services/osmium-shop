SELECT
    PP.product_id AS product_id
FROM
    { TABLE } AS PP
WHERE
    PP.page_id = :pageID
    AND PP.deleted_at IS NULL;
