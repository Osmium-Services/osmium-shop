UPDATE { PREFIX }products
SET alternatives = NULLIF(TRIM(BOTH ',' FROM REPLACE(CONCAT(',', alternatives, ','), CONCAT(',', :removed_id, ','), ',')), '')
WHERE FIND_IN_SET(:match_id, alternatives)
