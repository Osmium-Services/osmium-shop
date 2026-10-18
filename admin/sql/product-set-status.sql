-- Changes only the status. Moving to a status that is not a restocking one drops the restock date, which only makes sense while restocking.
UPDATE {PREFIX}products
SET status_id = :status_id,
    restock_at = IF(:restocking = 1, restock_at, NULL),
    updated_at = NOW()
WHERE id = :id AND deleted_at IS NULL
