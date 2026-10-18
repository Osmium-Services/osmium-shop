SELECT id, parent_id, label, title, subtitle, description, image, social_image, sort
FROM { PREFIX }productcategories
WHERE id = :id
AND deleted_at IS NULL
