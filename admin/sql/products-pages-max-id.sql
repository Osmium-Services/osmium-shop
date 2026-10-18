-- Find max page_id below admin threshold (page IDs >= 2000 are reserved for admin pages)
-- See OsmiumAdmin::ADMIN_PAGE_ID_THRESHOLD
SELECT MAX(page_id) as max_id
FROM { PREFIX }products_pages
WHERE page_id < 2000
