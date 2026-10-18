-- Append to the internal notes, never overwrite them.
-- Notes are an audit trail of what a human did about an order, so losing the
-- earlier ones to a careless save would defeat the point.
UPDATE {PREFIX}shop_orders
SET admin_notes = TRIM(BOTH '\n' FROM CONCAT(COALESCE(admin_notes, ''), '\n', :note))
WHERE id = :id
