SELECT id, descr_short, descr_long, restocking
FROM { PREFIX }productstatus
WHERE deleted_at IS NULL
ORDER BY id
