SELECT
  DISTINCT P.id
FROM
  { TABLE } AS P
  INNER JOIN { PREFIX } products_productcategories AS PC ON P.id = PC.product_id
WHERE
  P.deleted_at IS NULL
  AND PC.category_id = :categoryId
ORDER BY
  P.created_at DESC;
