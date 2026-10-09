<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

/**
 * Pulls the competitor list out of a model answer.
 *
 * Models are asked for bare JSON and do not always comply: some wrap it in a
 * markdown fence or add a sentence before it. The parser takes the outermost
 * JSON object it can find and ignores the rest. Anything that is not a plain
 * string of reasonable length is dropped, since this text ends up on an admin
 * page.
 */
final readonly class DiscoveryResponseParser
{
    private const MAX_NAME = 190;
    private const MAX_REASON = 400;
    private const MAX_DOMAIN = 190;

    /**
     * @return list<array{domain: string, name: ?string, reason: ?string}>|null null when no usable JSON object is found
     */
    public function parse(string $content): ?array
    {
        $start = strpos($content, '{');
        $end = strrpos($content, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($content, $start, $end - $start + 1), true);

        if (!\is_array($decoded) || !\is_array($decoded['competitors'] ?? null)) {
            return null;
        }

        $competitors = [];

        foreach ($decoded['competitors'] as $entry) {
            if (!\is_array($entry) || !\is_string($entry['domain'] ?? null)) {
                continue;
            }

            $domain = trim($entry['domain']);

            if ($domain === '' || mb_strlen($domain) > self::MAX_DOMAIN) {
                continue;
            }

            $competitors[] = [
                'domain' => $domain,
                'name' => $this->text($entry['name'] ?? null, self::MAX_NAME),
                'reason' => $this->text($entry['reason'] ?? null, self::MAX_REASON),
            ];
        }

        return $competitors;
    }

    private function text(mixed $value, int $max): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
