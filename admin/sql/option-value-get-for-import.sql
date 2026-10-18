SELECT id, option_premium, option_rrp, option_packed_weight
FROM { PREFIX }productoptionvalues
WHERE id = :id AND deleted_at IS NULL
