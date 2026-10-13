-- A doubt on the price or the size no longer hides an offer in the review
-- tab: it counts in the gaps, tagged to check (asked 2026-10-13). The offers
-- already waiting for such a reason follow, with their latest readings,
-- unless their price is one that never compares (an exclusion, or Jev
-- sure the price is a promotion, a bundle...).
UPDATE `pw_price_quote` q
    JOIN `pw_competitor_offer` o ON o.`id` = q.`offer_id`
    -- Derived, so MariaDB accepts reading the table it updates.
    JOIN (SELECT `offer_id`, MAX(`collected_day`) AS `last_day` FROM `pw_price_quote` GROUP BY `offer_id`) latest
        ON latest.`offer_id` = q.`offer_id` AND latest.`last_day` = q.`collected_day`
SET q.`comparable` = 1, q.`exclusion_reason` = NULL
WHERE o.`match_state` = 'needs_review'
  AND o.`match_reason` IN ('PRICE_GAP_SUSPECT', 'PRICE_GAP_IMPLAUSIBLE', 'VARIANT_UNCLEAR', 'REOPENED')
  AND (o.`exclusion_reason` IS NULL OR o.`exclusion_reason` IN ('NOT_JUDGED', 'PRICE_UNCLEAR', 'PRICE_GAP_IMPLAUSIBLE', 'PRICE_GAP_SUSPECT', 'unclear'))
  AND NOT (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.`judgment_json`, '$.price_comparability.band')), '') = 'act'
       AND JSON_UNQUOTE(JSON_EXTRACT(o.`judgment_json`, '$.price_comparability.value')) NOT IN ('comparable', 'unclear'))
  AND (q.`exclusion_reason` IS NULL OR q.`exclusion_reason` IN ('NOT_JUDGED', 'PRICE_UNCLEAR', 'PRICE_GAP_IMPLAUSIBLE', 'PRICE_GAP_SUSPECT', 'unclear'));

UPDATE `pw_competitor_offer`
SET `last_comparable` = 1, `exclusion_reason` = NULL
WHERE `match_state` = 'needs_review'
  AND `match_reason` IN ('PRICE_GAP_SUSPECT', 'PRICE_GAP_IMPLAUSIBLE', 'VARIANT_UNCLEAR', 'REOPENED')
  AND (`exclusion_reason` IS NULL OR `exclusion_reason` IN ('NOT_JUDGED', 'PRICE_UNCLEAR', 'PRICE_GAP_IMPLAUSIBLE', 'PRICE_GAP_SUSPECT', 'unclear'))
  AND NOT (COALESCE(JSON_UNQUOTE(JSON_EXTRACT(`judgment_json`, '$.price_comparability.band')), '') = 'act'
       AND JSON_UNQUOTE(JSON_EXTRACT(`judgment_json`, '$.price_comparability.value')) NOT IN ('comparable', 'unclear'));
