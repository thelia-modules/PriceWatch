<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

use PriceWatch\Service\Direct\DirectMatcher;
use PriceWatch\Service\Jev\JevAnswer;
use PriceWatch\Service\Jev\OfferQuestions;

/**
 * How far an offer can be trusted to be our product, in our size, at a
 * comparable price: what the screen shows as a level and three gauges.
 *
 * A chain is as strong as its weakest link: the level is the lowest of the
 * three answers, not their average. A human decision outranks any score, and
 * an offer decided by a code rule (a second-hand item, an implausible gap)
 * has no score to show.
 */
final readonly class MatchConfidence
{
    public const HIGH = 'high';
    public const MEDIUM = 'medium';
    public const LOW = 'low';
    public const HUMAN = 'human';
    public const RULE = 'rule';
    public const NONE = 'none';

    /** Read on the competitor's own page: one of our GTINs, or a size on a page confirmed by hand. */
    public const PROOF = 'proof';

    /** Same threshold as the decisive band: below it, Jev would not decide. */
    private const HIGH_FROM = JevAnswer::DECISIVE;
    private const MEDIUM_FROM = 0.50;

    /**
     * @param array<string, mixed> $judgment as stored on the offer (judgment_json)
     *
     * A match a human confirmed against a clear "no" from Jev is shown as a
     * disagreement: the decision stands, but nobody should overlook it.
     * Seen on 2026-10-09: a helmet confirmed by hand while Jev gave 4 % to
     * "same size" (XS at the competitor, M at ours).
     *
     * @return array{level: string, score: ?int, gauges: list<array{question: string, percent: int, band: string, choice: ?string}>, disagreements: list<string>}
     */
    public static function of(?string $method, array $judgment, ?string $state = null): array
    {
        $gauges = [];

        foreach ([OfferQuestions::SAME_PRODUCT, OfferQuestions::SAME_VARIANT, OfferQuestions::PRICE_COMPARABILITY] as $question) {
            $answer = $judgment[$question] ?? null;

            if (!\is_array($answer)) {
                continue;
            }

            $value = $answer['value'] ?? null;
            // Yes/no questions carry a probability; the price question the
            // confidence of the option chosen.
            $probability = is_numeric($value) ? (float) $value : (is_numeric($answer['confidence'] ?? null) ? (float) $answer['confidence'] : null);

            if ($probability === null) {
                continue;
            }

            $gauges[] = [
                'question' => $question,
                'percent' => (int) round($probability * 100),
                'band' => (string) ($answer['band'] ?? ''),
                'choice' => is_numeric($value) ? null : (string) $value,
            ];
        }

        $score = $gauges === [] ? null : min(array_column($gauges, 'percent'));

        $level = match (true) {
            $method === 'human' => self::HUMAN,
            $method === 'code' => self::RULE,
            $method === DirectMatcher::BY_GTIN || $method === DirectMatcher::BY_SIZE => self::PROOF,
            $score === null => self::NONE,
            $score >= (int) round(self::HIGH_FROM * 100) => self::HIGH,
            $score >= (int) round(self::MEDIUM_FROM * 100) => self::MEDIUM,
            default => self::LOW,
        };

        $disagreements = $level === self::HUMAN && \in_array($state, ComparatorView::MATCHED_STATES, true)
            ? array_column(array_filter($gauges, static fn (array $gauge): bool => $gauge['band'] === JevAnswer::NO), 'question')
            : [];

        return ['level' => $level, 'score' => $score, 'gauges' => $gauges, 'disagreements' => $disagreements];
    }
}
