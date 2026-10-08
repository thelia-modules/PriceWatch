<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

/**
 * A competitor is identified by its registrable domain, so that
 * https://www.Dafy-Moto.com/casque/xyz?utm=1 and dafy-moto.com are one and the
 * same competitor.
 *
 * Known limit: the multi-label suffixes below are a short hand-kept list, not
 * the Public Suffix List. A domain under an uncovered multi-label suffix would
 * be truncated to two labels and could merge two distinct competitors. The
 * back-office shows the registrable domain next to the host it came from so
 * that such a case is visible rather than silent.
 */
final readonly class DomainNormalizer
{
    private const MULTI_LABEL_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'com.au', 'net.au', 'org.au', 'co.nz', 'co.za',
        'com.br', 'com.mx', 'com.ar', 'com.tr', 'com.cn', 'com.hk', 'com.sg',
        'co.jp', 'co.in', 'co.kr', 'com.pl', 'com.es', 'com.pt', 'com.ua',
    ];

    public function host(?string $raw): ?string
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        if (!str_contains($value, '//')) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, \PHP_URL_HOST);

        if (!\is_string($host) || $host === '') {
            return null;
        }

        $host = rtrim(strtolower($host), '.');
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host) === 1 ? $host : null;
    }

    public function registrableDomain(?string $raw): ?string
    {
        $host = $this->host($raw);

        if ($host === null) {
            return null;
        }

        $labels = explode('.', $host);
        $labelCount = \count($labels);

        if ($labelCount <= 2) {
            return $host;
        }

        $lastTwo = implode('.', \array_slice($labels, -2));
        $keep = \in_array($lastTwo, self::MULTI_LABEL_SUFFIXES, true) ? 3 : 2;

        return implode('.', \array_slice($labels, -min($keep, $labelCount)));
    }
}
