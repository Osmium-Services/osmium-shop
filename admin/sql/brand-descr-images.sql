SELECT id, descr_image, descr2_image, descr3_image
FROM { PREFIX }products
WHERE brand_id = :id
  AND (descr_image LIKE :like OR descr2_image LIKE :like OR descr3_image LIKE :like)
