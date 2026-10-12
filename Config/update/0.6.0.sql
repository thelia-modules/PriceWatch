-- A price Jev was unsure about now counts in the gap (MatchDecision): the
-- matches Jev made before are brought in line, readings included.
UPDATE `pw_price_quote` q
    JOIN `pw_competitor_offer` o ON o.`id` = q.`offer_id`
SET q.`comparable` = 1, q.`exclusion_reason` = NULL
WHERE q.`exclusion_reason` = 'PRICE_UNCLEAR'
  AND o.`match_state` = 'matched_judged'
  AND o.`match_method` = 'jev';

UPDATE `pw_competitor_offer`
SET `last_comparable` = 1, `exclusion_reason` = NULL
WHERE `exclusion_reason` = 'PRICE_UNCLEAR'
  AND `match_state` = 'matched_judged'
  AND `match_method` = 'jev';
