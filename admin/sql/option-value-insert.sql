INSERT INTO { PREFIX }productoptionvalues
(option_type_id, option_descr, option_premium, option_rrp, option_packed_weight, option_reference, linked_product_id, linked_image_slot, sort)
VALUES (:type_id, :descr, :premium, :rrp, :weight, :reference, :linked_id, :linked_image_slot, :sort)
