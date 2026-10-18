UPDATE {PREFIX}discounts
SET times_used = times_used + 1
WHERE id = :id
  AND (usage_limit IS NULL OR times_used < usage_limit)
