UPDATE { PREFIX }productcategories
SET parent_id = :parent_id,
    page_id = :page_id,
    label = :label,
    title = :title,
    subtitle = :subtitle,
    description = :description,
    image = :image,
    social_image = :social_image
WHERE id = :id
