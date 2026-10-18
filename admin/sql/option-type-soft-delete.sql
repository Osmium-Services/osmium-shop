UPDATE { PREFIX }productoptiontypes
SET deleted_at = NOW()
WHERE id = :id
