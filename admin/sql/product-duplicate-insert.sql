INSERT INTO { PREFIX }products (
    brand_id, model_name, reference, barcode, status_id,
    price_exc_tax, rrp_exc_tax, custom_offer, descr, descr2, descr3,
    video_url, techspec, features, accessories, faq, faq_list, packed_weight,
    option_label, option_default, created_at
) VALUES (
    :brand_id, :model_name, :reference, :barcode, :status_id,
    :price_exc_tax, :rrp_exc_tax, :custom_offer, :descr, :descr2, :descr3,
    :video_url, :techspec, :features, :accessories, :faq, :faq_list, :packed_weight,
    :option_label, :option_default, NOW()
)
