-- A price far from ours is a doubt to check, never a verdict (MatchDecision::withGap).

-- 1. Jev matches at a gap of 25 % or more counted in the gap: a human looks.
--    Readings first, while the offers still say which ones they are.
UPDATE `pw_price_quote` q
    JOIN `pw_competitor_offer` o ON o.`id` = q.`offer_id`
SET q.`comparable` = 0,
    q.`exclusion_reason` = IF(ABS(o.`last_delta_pct`) > 70, 'PRICE_GAP_IMPLAUSIBLE', 'PRICE_GAP_SUSPECT')
WHERE o.`match_state` = 'matched_judged'
  AND o.`match_method` = 'jev'
  AND o.`last_comparable` = 1
  AND ABS(o.`last_delta_pct`) >= 25
  AND q.`comparable` = 1;

UPDATE `pw_competitor_offer`
SET `match_state` = 'needs_review',
    `match_reason` = IF(ABS(`last_delta_pct`) > 70, 'PRICE_GAP_IMPLAUSIBLE', 'PRICE_GAP_SUSPECT'),
    `exclusion_reason` = IF(ABS(`last_delta_pct`) > 70, 'PRICE_GAP_IMPLAUSIBLE', 'PRICE_GAP_SUSPECT'),
    `last_comparable` = 0
WHERE `match_state` = 'matched_judged'
  AND `match_method` = 'jev'
  AND `last_comparable` = 1
  AND ABS(`last_delta_pct`) >= 25;

-- 2. Offers rejected on the gap alone, by code, never by a human: judged
--    again at the next pass.
UPDATE `pw_competitor_offer`
SET `match_state` = 'candidate',
    `match_method` = NULL,
    `match_reason` = NULL,
    `exclusion_reason` = NULL,
    `judged_fingerprint` = NULL
WHERE `match_state` = 'rejected'
  AND `match_method` = 'code'
  AND `match_reason` = 'PRICE_GAP_IMPLAUSIBLE'
  AND `reviewed_by` IS NULL;
