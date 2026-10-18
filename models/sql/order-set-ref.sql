-- Assign the human-readable reference once the id is known.
-- NC- + (1000 + id), so the first order is NC-1001. Failed and abandoned
-- checkouts consume numbers, so this sequence has gaps by design.
UPDATE {PREFIX}shop_orders
SET order_ref = :order_ref
WHERE id = :id
