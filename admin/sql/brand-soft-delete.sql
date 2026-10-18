UPDATE { PREFIX }productbrands
SET deleted_at = NOW()
WHERE id = :id
