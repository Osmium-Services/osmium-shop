SELECT COALESCE(MAX(sort), -1) + 1 as next_sort
FROM { PREFIX }productoptiontypes
WHERE product_id = :product_id
