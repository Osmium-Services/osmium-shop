-- Take the next invoice number under the counter row's own lock.
-- MAX(invoice_number)+1 would race two simultaneous captures into the same
-- number, and duplicate VAT invoice numbers are not a problem to discover late.
UPDATE {PREFIX}invoice_counter
SET next_invoice_number = LAST_INSERT_ID(next_invoice_number) + 1
WHERE id = 1
