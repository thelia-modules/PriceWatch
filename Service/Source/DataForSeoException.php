<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

/**
 * Messages come from DataForSEO status codes and texts only, never from the
 * request: the credentials cannot end up in a log or on a page.
 */
final class DataForSeoException extends \RuntimeException
{
    /**
     * Not an error: the task is not processed yet (DataForSEO appendix,
     * read 2026-10-09: 40601 Task Handed, 40602 Task In Queue, 50303 Update
     * in progress).
     */
    private const NOT_READY = [40601, 40602, 50303];

    public static function fromStatus(int $statusCode, string $statusMessage): self
    {
        // The status code is kept as the exception code, so callers can tell
        // "not ready yet" from "failed".
        return new self(match ($statusCode) {
            40100 => 'DataForSEO refused the credentials. The API password is the one shown on app.dataforseo.com/api-access, not the account password.',
            // The two codes observed on this account (2026-10-09). Others pass
            // through with DataForSEO's own message rather than a guess.
            40104 => 'DataForSEO requires the account to be verified before any task can be posted.',
            // Documented in the DataForSEO appendix (read 2026-10-09).
            40200, 40210 => 'DataForSEO has no funds left on the account: top it up on app.dataforseo.com before the next pass.',
            default => \sprintf('DataForSEO answered %d: %s', $statusCode, mb_substr($statusMessage, 0, 200)),
        }, $statusCode);
    }

    public function isNotReady(): bool
    {
        return \in_array($this->getCode(), self::NOT_READY, true);
    }
}
