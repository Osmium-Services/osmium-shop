UPDATE { PREFIX }productoptionvalues
SET option_premium = :premium, option_rrp = :rrp, option_packed_weight = :weight
WHERE id = :id
