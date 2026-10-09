<?php

declare(strict_types=1);

namespace PriceWatch\Service\Comparator;

/**
 * Where our price stands against one competitor offer, from our side: the
 * screen colours it green when we are cheaper, red when we are dearer.
 *
 * Gaps are stored as (their price - our price) / our price, in percent:
 * negative means the competitor is cheaper, so we are dearer.
 */
enum PricePosition: string
{
    case WeAreCheaper = 'cheaper';
    case WeAreDearer = 'dearer';
    case Equal = 'equal';
    case Unknown = 'unknown';

    /**
     * Equal is what the screen shows as 0.0 %: a gap that rounds to zero at
     * one decimal is not coloured, whichever side it leans to.
     */
    public const EQUAL_BELOW = 0.05;

    public static function fromGapPct(string|float|null $gapPct): self
    {
        if ($gapPct === null || $gapPct === '') {
            return self::Unknown;
        }

        $gap = (float) $gapPct;

        return match (true) {
            abs($gap) < self::EQUAL_BELOW => self::Equal,
            $gap > 0 => self::WeAreCheaper,
            default => self::WeAreDearer,
        };
    }
}
