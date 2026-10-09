<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

/**
 * Watched is the whitelist: the only competitors whose prices are compared.
 * Rejected is the blacklist: never compared, and never suggested again by any
 * discovery pass. Proposed waits for a human decision.
 */
enum CompetitorStatus: string
{
    case Proposed = 'proposed';
    case Watched = 'watched';
    case Rejected = 'rejected';
    case Paused = 'paused';
}
