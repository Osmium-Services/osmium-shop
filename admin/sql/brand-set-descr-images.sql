UPDATE { PREFIX }products
SET descr_image = :descr_image,
    descr2_image = :descr2_image,
    descr3_image = :descr3_image
WHERE id = :id
