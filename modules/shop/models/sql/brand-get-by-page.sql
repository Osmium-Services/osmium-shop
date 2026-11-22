SELECT id, name, info_external, logo
FROM { TABLE }
WHERE page_id = :pageID
    AND deleted_at IS NULL
