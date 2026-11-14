-- Option types and values of every live product, for the shop.product.options hook. The default option has no value row:
-- it is the type's default_option_descr, at no extra cost. No customer data.
SELECT
    POT.product_id,
    POT.id AS type_id,
    POT.type_label,
    POT.default_option_descr,
    POV.id AS value_id,
    POV.option_descr,
    POV.option_premium,
    POV.option_reference
FROM {PREFIX}productoptiontypes AS POT
INNER JOIN {PREFIX}products AS P ON P.id = POT.product_id AND P.deleted_at IS NULL
LEFT JOIN {PREFIX}productoptionvalues AS POV ON POV.option_type_id = POT.id AND POV.deleted_at IS NULL
WHERE POT.deleted_at IS NULL
ORDER BY POT.product_id ASC, POT.sort ASC, POT.id ASC, POV.sort ASC, POV.id ASC
