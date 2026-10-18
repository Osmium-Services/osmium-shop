-- Lock an order row for the duration of the surrounding transaction.
--
-- Payment marking reads the status and takes an invoice number as one
-- operation. Without the lock, two concurrent captures could both see
-- 'pending', both take a number, and one of those numbers would be wasted -
-- which is exactly the gap in the invoice sequence the counter exists to avoid.
SELECT id, status, order_ref, total_inc_tax, currency, invoice_number, customer_email, discount_id, discount_code
FROM {PREFIX}shop_orders
WHERE id = :id
FOR UPDATE
