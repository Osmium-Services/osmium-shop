SELECT id, name, page_id, deleted_at
FROM { PREFIX }productbrands
WHERE page_id IS NOT NULL
