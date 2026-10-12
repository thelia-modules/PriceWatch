<?php

declare(strict_types=1);

namespace PriceWatch\Service\Direct;

/**
 * What became of a competitor page. Only Read carries prices: every other
 * outcome records nothing, so a page never yields a guessed price.
 */
enum PageOutcome: string
{
    case Read = 'read';

    /** No product data, or none that is this page's. */
    case NoProduct = 'no_product';

    /** No price in euros: missing, mixed or foreign currency. */
    case NoCurrency = 'no_currency';

    /** The page's structured data disagree on the price. */
    case Contradictory = 'contradictory';

    case Disallowed = 'disallowed';
    case HttpError = 'http_error';
}
