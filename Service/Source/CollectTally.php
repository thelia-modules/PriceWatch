<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

/**
 * What a collect pass has done so far. Kept outside the pass so that a pass
 * stopped by an error still records what it paid for and why it stopped.
 */
final class CollectTally
{
    /** @var list<string> */
    public array $errors = [];

    /** @var array<string, int> seller => offers seen */
    public array $unknownSellers = [];

    /** @var array<string, array{name: string, offers: int}> unknown sellers whose site is identifiable, by registrable domain */
    public array $sellersToPropose = [];

    /** @var array<string, list<string>> our title => first card titles */
    public array $noCard = [];

    public int $targets = 0;
    public int $tasks = 0;
    public int $seen = 0;
    public int $written = 0;

    /** Whitelisted offers left out because the card showed another of our sizes. */
    public int $otherSize = 0;
    public float $costUsd = 0.0;
}
