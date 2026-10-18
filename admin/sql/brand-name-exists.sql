-- A live brand with this name, other than the one given (id 0 for a new brand). The column is case-insensitive.
SELECT id FROM {PREFIX}productbrands WHERE name = :name AND id != :id AND deleted_at IS NULL LIMIT 1
