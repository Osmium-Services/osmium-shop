SELECT
    `POT`.`id` AS `type_id`,
    `POT`.`type_label`,
    `POT`.`type_slug`,
    `POT`.`default_option_descr`,
    `POT`.`linked_product_id`,
    `POT`.`linked_product_descr`,
    `POT`.`linked_image_slot` AS `type_linked_image_slot`,
    `POT`.`sort` AS `type_sort`,
    `POV`.`id` AS `value_id`,
    `POV`.`option_rrp`,
    `POV`.`option_premium`,
    `POV`.`option_descr`,
    `POV`.`option_packed_weight`,
    `POV`.`option_reference`,
    `POV`.`linked_product_id` AS `value_linked_product_id`,
    `POV`.`linked_image_slot` AS `value_linked_image_slot`,
    `POV`.`sort` AS `value_sort`,
    `LPVS`.`product_available` AS `linked_value_product_available`,
    `LPVS`.`restocking` AS `linked_value_product_restocking`,
    `LPV`.`restock_at` AS `linked_value_product_restock_at`,
    CONCAT(`LPB`.`name`, ' ', `LP`.`model_name`) AS `linked_product_title`,
    `LP`.`descr` AS `linked_product_description`,
    LOWER(REPLACE(`LPB`.`name`, ' ', '-')) AS `linked_product_brand`,
    LOWER(
        REPLACE(REPLACE(`LP`.`model_name`, ' ', '-'), '.', '_')
    ) AS `linked_product_model`,
    `LPPP`.`page_id` AS `linked_product_page_id`,
    `LPS`.`product_available` AS `linked_product_available`,
    `LPS`.`restocking` AS `linked_product_restocking`,
    `LP`.`restock_at` AS `linked_product_restock_at`,
    `POT`.`default_linked_product_id`,
    `DLPS`.`product_available` AS `default_linked_product_available`,
    `DLPS`.`restocking` AS `default_linked_product_restocking`,
    `DLP`.`restock_at` AS `default_linked_product_restock_at`
FROM
    `{PREFIX}productoptiontypes` AS `POT`
    LEFT JOIN `{PREFIX}productoptionvalues` AS `POV` ON `POV`.`option_type_id` = `POT`.`id`
    AND `POV`.`deleted_at` IS NULL
    LEFT JOIN `{PREFIX}products` AS `LP` ON `LP`.`id` = `POT`.`linked_product_id`
    LEFT JOIN `{PREFIX}productbrands` AS `LPB` ON `LPB`.`id` = `LP`.`brand_id`
    LEFT JOIN `{PREFIX}products_pages` AS `LPPP` ON `LPPP`.`product_id` = `LP`.`id`
    AND `LPPP`.`is_qr` = 0
    LEFT JOIN `{PREFIX}productstatus` AS `LPS` ON `LPS`.`id` = `LP`.`status_id`
    AND `LPS`.`deleted_at` IS NULL
    LEFT JOIN `{PREFIX}products` AS `DLP` ON `DLP`.`id` = `POT`.`default_linked_product_id`
    LEFT JOIN `{PREFIX}productstatus` AS `DLPS` ON `DLPS`.`id` = `DLP`.`status_id`
    AND `DLPS`.`deleted_at` IS NULL
    LEFT JOIN `{PREFIX}products` AS `LPV` ON `LPV`.`id` = `POV`.`linked_product_id`
    LEFT JOIN `{PREFIX}productstatus` AS `LPVS` ON `LPVS`.`id` = `LPV`.`status_id`
    AND `LPVS`.`deleted_at` IS NULL
WHERE
    `POT`.`product_id` = :productID
    AND `POT`.`deleted_at` IS NULL
ORDER BY
    `POT`.`sort` ASC,
    `POV`.`sort` ASC
LIMIT
    100;
