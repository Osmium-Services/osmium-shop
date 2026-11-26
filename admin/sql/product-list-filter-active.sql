-- Live products with a brand, status and page. Category 0 = all.
WHERE P.deleted_at IS NULL
  AND PB.id IS NOT NULL AND PB.deleted_at IS NULL
  AND PS.id IS NOT NULL AND PS.deleted_at IS NULL
  AND PP.page_id IS NOT NULL
  AND (:categoryAll = 0 OR EXISTS (
      SELECT 1 FROM { PREFIX }products_productcategories AS FC
      WHERE FC.product_id = P.id AND FC.category_id = :categoryId AND FC.deleted_at IS NULL
  ))
