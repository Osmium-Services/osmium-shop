-- Mark an order paid, assigning its gapless invoice number.
--
-- status = 'pending' in the WHERE clause is the idempotency guard: a second
-- capture, a double-clicked button or a webhook arriving after the browser
-- already confirmed will all update zero rows rather than paying twice,
-- issuing a second invoice number or sending a second confirmation email.
UPDATE {PREFIX}shop_orders
SET status = 'paid',
    invoice_number = :invoice_number,
    payment_capture_ref = :payment_capture_ref,
    payment_payer_email = :payment_payer_email,
    paid_at = NOW()
WHERE id = :id
  AND status = 'pending'
