-- "Same article?" below 0.35 is a rejection (MatchDecision::PRODUCT_UNLIKELY_BELOW),
-- no longer a review: offers Jev sent to review on such a score follow, unless
-- a human already decided on them.
UPDATE `pw_competitor_offer`
SET `match_state` = 'rejected',
    `match_reason` = 'PRODUCT_UNLIKELY'
WHERE `match_state` = 'needs_review'
  AND `match_method` = 'jev'
  AND `match_reason` = 'PRODUCT_UNCLEAR'
  AND `reviewed_by` IS NULL
  AND CAST(JSON_UNQUOTE(JSON_EXTRACT(`judgment_json`, '$.same_product.value')) AS DECIMAL(6, 4)) < 0.35;
