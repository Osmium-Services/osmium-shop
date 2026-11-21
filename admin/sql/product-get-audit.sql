-- One live product for a content audit: identity, price, status, and the size or presence of each content field.
-- Lengths are taken here, so no description, FAQ or tech spec text leaves the database. No customer data.
SELECT
    P.id,
    P.reference,
    P.model_name,
    PB.name AS brand_name,
    PS.descr_short AS status_name,
    PS.product_available AS available,
    COALESCE(PS.restocking, 0) AS restocking,
    P.restock_at,
    P.price_exc_tax,
    P.rrp_exc_tax,
    CHAR_LENGTH(TRIM(P.descr)) AS descr_length,
    CHAR_LENGTH(TRIM(P.descr2)) AS descr2_length,
    CHAR_LENGTH(TRIM(P.descr3)) AS descr3_length,
    (COALESCE(P.descr_image, '') <> '') AS descr_image_set,
    (COALESCE(P.descr2_image, '') <> '') AS descr2_image_set,
    (COALESCE(P.descr3_image, '') <> '') AS descr3_image_set,
    CHAR_LENGTH(TRIM(COALESCE(P.faq, ''))) AS faq_length,
    CHAR_LENGTH(TRIM(COALESCE(P.techspec, ''))) AS techspec_length,
    (COALESCE(P.video_url, '') <> '') AS video_set,
    (COALESCE(P.barcode, '') <> '') AS barcode_set,
    COALESCE(P.features, '') AS features,
    COALESCE(P.accessories, '') AS accessories,
    COALESCE(P.alternatives, '') AS alternatives,
    P.created_at,
    P.updated_at
FROM {PREFIX}products AS P
LEFT JOIN {PREFIX}productbrands AS PB ON PB.id = P.brand_id
LEFT JOIN {PREFIX}productstatus AS PS ON PS.id = P.status_id
WHERE P.id = :id
  AND P.deleted_at IS NULL
