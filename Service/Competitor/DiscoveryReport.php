<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

final class DiscoveryReport
{
    /** @var list<string> */
    public array $proposed = [];

    /** @var list<string> already whitelisted, proposed or paused */
    public array $alreadyKnown = [];

    /** @var list<string> on the blacklist, so never suggested again */
    public array $blacklisted = [];

    /** @var list<string> not a usable domain, or our own */
    public array $invalid = [];

    /** @var list<string> no DNS record: most likely an invented shop */
    public array $unresolved = [];

    public ?string $model = null;
    public int $promptTokens = 0;
    public int $completionTokens = 0;
}
