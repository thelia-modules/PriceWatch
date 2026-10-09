<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

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

    /** Same threshold as the decisive band: below it, Jev would not decide. */
    private const HIGH_FROM = JevAnswer::DECISIVE;
    private const MEDIUM_FROM = 0.50;

    /**
     * @param array<string, mixed> $judgment as stored on the offer (judgment_json)
     *
     * @return array{level: string, score: ?int, gauges: list<array{question: string, percent: int, band: string, choice: ?string}>}
     */
    public static function of(?string $method, array $judgment): array
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
            $score === null => self::NONE,
            $score >= (int) round(self::HIGH_FROM * 100) => self::HIGH,
            $score >= (int) round(self::MEDIUM_FROM * 100) => self::MEDIUM,
            default => self::LOW,
        };

        return ['level' => $level, 'score' => $score, 'gauges' => $gauges];
    }
}
