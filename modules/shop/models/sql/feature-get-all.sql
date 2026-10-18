SELECT
    PF.id,
    PF.title,
    PF.icon,
    PF.link_page_id
FROM
    { TABLE } AS PF
WHERE
    PF.deleted_at IS NULL;
