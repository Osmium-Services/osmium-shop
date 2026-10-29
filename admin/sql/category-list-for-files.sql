-- Every live category, so a product's category can be given with its path from the top
SELECT id, parent_id, label, sort FROM {PREFIX}productcategories WHERE deleted_at IS NULL
