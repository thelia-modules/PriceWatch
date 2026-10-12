<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

use PriceWatch\Service\Normalizer\GtinNormalizer;

/**
 * The value rules every extractor shares.
 */
final class PageValues
{
    private const TRACKING_PARAMETERS = '/^(utm_.*|srsltid|gclid|gbraid|wbraid|fbclid|msclkid)$/i';

    public static function price(mixed $value): ?string
    {
        if (\is_string($value)) {
            $value = str_replace([' ', "\u{a0}"], '', $value);
        }

        return is_numeric($value) && (float) $value > 0.0 ? number_format((float) $value, 2, '.', '') : null;
    }

    public static function text(mixed $value): ?string
    {
        if (\is_array($value)) {
            $value = $value['name'] ?? null;
        }

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A GTIN wherever the page writes it: 8, 12, 13 or 14 digits with a valid
     * check digit, as 14 digits. A maker code or an internal reference is no
     * GTIN and is never taken for one.
     */
    public static function gtin(GtinNormalizer $normalizer, mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (!\is_string($candidate) && !\is_int($candidate)) {
                continue;
            }

            if (preg_match('/^\d{8}$|^\d{12,14}$/', trim((string) $candidate)) !== 1) {
                continue;
            }

            $gtin = $normalizer->normalize((string) $candidate);

            if ($gtin->value !== null && $gtin->checkDigitValid) {
                return $gtin->value;
            }
        }

        return null;
    }

    /**
     * Same page: same host (www. aside) and same path. The query string only
     * preselects a size or tracks a click.
     */
    public static function samePage(?string $declared, string $page): bool
    {
        if ($declared === null || $declared === '') {
            return false;
        }

        $a = parse_url($declared);
        $b = parse_url($page);

        if (!\is_array($a) || !\is_array($b)) {
            return false;
        }

        $hostA = isset($a['host']) ? preg_replace('/^www\./', '', strtolower($a['host'])) : null;
        $hostB = isset($b['host']) ? preg_replace('/^www\./', '', strtolower($b['host'])) : null;

        if ($hostA !== null && $hostA !== $hostB) {
            return false;
        }

        return rtrim($a['path'] ?? '/', '/') === rtrim($b['path'] ?? '/', '/');
    }

    /**
     * The page address without the tracking parameters Google and ads append:
     * two clicks on the same product are one page.
     */
    public static function cleanUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $query = [];

        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair !== '' && preg_match(self::TRACKING_PARAMETERS, explode('=', $pair, 2)[0]) !== 1) {
                $query[] = $pair;
            }
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '')
            .($parts['path'] ?? '/')
            .($query === [] ? '' : '?'.implode('&', $query));
    }
}
