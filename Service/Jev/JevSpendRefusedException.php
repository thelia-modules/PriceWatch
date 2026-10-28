<?php

declare(strict_types=1);

namespace PriceWatch\Service\Jev;

/**
 * The spending rules of the AI layer stopped the call before it was made
 * (budget reached, usage off, EU-only service). Asking again within the same
 * pass would be refused the same way: the pass stops asking.
 */
final class JevSpendRefusedException extends JevException
{
}
