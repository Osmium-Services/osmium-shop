SELECT id, name
FROM { PREFIX }productbrands
WHERE deleted_at IS NULL
ORDER BY name
