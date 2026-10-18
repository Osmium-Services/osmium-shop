-- Total item quantity across an active basket's lines, for the header cart icon.
SELECT COALESCE(SUM(bi.quantity), 0) AS item_count
FROM {PREFIX}checkout_basket_items bi
JOIN {PREFIX}checkout_baskets b ON b.id = bi.basket_id
WHERE b.session_id = :session_id
  AND b.status = 'active'
