SELECT
    techspec,
    faq
FROM
    { PREFIX }products
WHERE
    id = :id;
