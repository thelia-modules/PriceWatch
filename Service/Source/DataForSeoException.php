<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

/**
 * Messages come from DataForSEO status codes and texts only, never from the
 * request: the credentials cannot end up in a log or on a page.
 */
final class DataForSeoException extends \RuntimeException
{
    public static function fromStatus(int $statusCode, string $statusMessage): self
    {
        return new self(match ($statusCode) {
            40100 => 'DataForSEO refused the credentials. The API password is the one shown on app.dataforseo.com/api-access, not the account password.',
            // The two codes observed on this account (2026-10-09). Others pass
            // through with DataForSEO's own message rather than a guess.
            40104 => 'DataForSEO requires the account to be verified before any task can be posted.',
            default => \sprintf('DataForSEO answered %d: %s', $statusCode, mb_substr($statusMessage, 0, 200)),
        });
    }
}
