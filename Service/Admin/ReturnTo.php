<?php

declare(strict_types=1);

namespace PriceWatch\Service\Admin;

use PriceWatch\Service\Comparator\ComparatorFilters;
use PriceWatch\Service\Watched\WatchedFilters;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Where an action started from a list goes back to. The address is always
 * rebuilt from a known route and validated parameters, never taken as given:
 * a form field cannot send the browser anywhere else.
 */
final class ReturnTo
{
    public const WATCHED_LIST = 'watched';
    public const CATEGORY_LIST = 'category';
    public const COMPARATOR_LIST = 'comparator';

    public static function url(Request $request, UrlGeneratorInterface $urlGenerator): ?string
    {
        $back = $request->request->all()['back'] ?? null;
        parse_str(\is_string($return = $request->request->all()['return'] ?? null) ? $return : '', $query);
        $query = \is_array($query) ? $query : [];

        return match ($back) {
            self::WATCHED_LIST => $urlGenerator->generate('pricewatch_watched', WatchedFilters::fromRequest(new Request($query))->toQueryParams()),
            self::COMPARATOR_LIST => $urlGenerator->generate('pricewatch_comparator', ComparatorFilters::fromRequest(new Request($query))->toQueryParams()),
            self::CATEGORY_LIST => $urlGenerator->generate('admin.categories.default', array_filter([
                'category_id' => self::positiveInt($query['category_id'] ?? null),
                'page' => self::positiveInt($query['page'] ?? null),
            ])),
            default => null,
        };
    }

    private static function positiveInt(mixed $value): ?int
    {
        return (\is_string($value) || \is_int($value)) && preg_match('/^[1-9]\d{0,8}$/', (string) $value) === 1 ? (int) $value : null;
    }
}
