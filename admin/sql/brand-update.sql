UPDATE { PREFIX }productbrands
SET name = :name,
    page_url = :page_url,
    info_external = :info_external
WHERE id = :id
