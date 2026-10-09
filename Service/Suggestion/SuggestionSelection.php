<?php

declare(strict_types=1);

namespace PriceWatch\Service\Suggestion;

/**
 * The products ticked in the suggestions tab, read from the form as posted:
 * anything that is not a product id is left out, never trusted.
 */
final readonly class SuggestionSelection
{
    /**
     * @return list<int>|null the product ids, or null when more than one pass may take
     */
    public static function productIds(mixed $posted): ?array
    {
        $ids = [];

        foreach (\is_array($posted) ? $posted : [] as $value) {
            if ((\is_string($value) || \is_int($value)) && preg_match('/^[1-9]\d{0,8}$/', (string) $value) === 1) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return \count($ids) > SuggestionPresenter::MAX_SELECTED ? null : array_values($ids);
    }
}
