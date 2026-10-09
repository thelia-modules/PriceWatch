<?php

declare(strict_types=1);

namespace PriceWatch\Service\Referential;

/**
 * What adding a product to the price watch did, variant by variant.
 */
final class EnrolmentReport
{
    /** Variants new to the referential. */
    public int $added = 0;

    /** Variants already in it but paused, monitored again. */
    public int $resumed = 0;

    /** Variants already monitored: nothing to do. */
    public int $alreadyMonitored = 0;

    /** @var list<array{variant: string, reason: string}> variants the referential's checks refused */
    public array $refused = [];
}
