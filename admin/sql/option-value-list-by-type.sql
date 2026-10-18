SELECT id, option_descr, option_premium, option_rrp,
       option_packed_weight, option_reference, sort
FROM { PREFIX }productoptionvalues
WHERE option_type_id = :type_id AND deleted_at IS NULL
ORDER BY sort, id
