<?php

declare(strict_types=1);

namespace PriceWatch\Service\Normalizer;

enum SizeFamily: string
{
    case Alpha = 'alpha';
    case Numeric = 'numeric';
    case Unknown = 'unknown';
}
