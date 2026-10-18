SELECT
    POV.id as option_id,
    POT.product_id,
    POT.type_label,
    POV.option_descr,
    POV.option_reference,
    POV.option_premium,
    POV.option_rrp,
    POV.option_packed_weight
FROM { PREFIX }productoptionvalues AS POV
INNER JOIN { PREFIX }productoptiontypes AS POT ON POT.id = POV.option_type_id
INNER JOIN { PREFIX }products AS P ON P.id = POT.product_id
WHERE POV.deleted_at IS NULL
  AND POT.deleted_at IS NULL
  AND P.deleted_at IS NULL
ORDER BY POT.product_id, POT.sort, POV.sort
