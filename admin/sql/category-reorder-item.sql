UPDATE { PREFIX }productcategories
SET sort = :sort, parent_id = :parent_id
WHERE id = :id
