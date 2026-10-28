<?php

declare(strict_types=1);

namespace PriceWatch\Service\Ai;

/**
 * The AI layer of the core refused a paid call before it was made: the usage
 * is switched off, a budget is reached, or the merchant allows EU services
 * only. Nothing was spent. The message is the core's own, fit for a log or
 * for the administrator.
 */
final class SpendRefusedException extends \RuntimeException
{
}
