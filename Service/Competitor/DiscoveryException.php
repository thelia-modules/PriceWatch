<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

use Thelia\Domain\Ai\Exception\AiExceptionInterface;

/**
 * Competitor discovery did not run, or what came back was unusable. Messages
 * are fit for the administrator: the AI layer words its own refusals.
 */
final class DiscoveryException extends \RuntimeException
{
    public static function disabled(): self
    {
        return new self('Competitor discovery is switched off in the module settings.');
    }

    public static function malformed(string $detail): self
    {
        return new self('The model answered something unusable: '.$detail.'.');
    }

    public static function refused(AiExceptionInterface $refusal): self
    {
        return new self($refusal->getMessage(), 0, $refusal);
    }
}
