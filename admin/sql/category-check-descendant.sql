WITH RECURSIVE descendants AS (
    SELECT id FROM { PREFIX }productcategories WHERE parent_id = :id AND deleted_at IS NULL
    UNION ALL
    SELECT pc.id FROM { PREFIX }productcategories pc
    INNER JOIN descendants d ON pc.parent_id = d.id WHERE pc.deleted_at IS NULL
)
SELECT 1 FROM descendants WHERE id = :parent_id
