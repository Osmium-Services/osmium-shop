DELETE v FROM { PREFIX }productoptionvalues v
INNER JOIN { PREFIX }productoptiontypes t ON v.option_type_id = t.id
WHERE t.product_id = :product_id
