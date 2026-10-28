<?php

declare(strict_types=1);

namespace PriceWatch\Service\Jev;

/**
 * A failed judgment is recorded as not judged, never as a pass. Messages are
 * built from status codes and provider texts only, never from headers.
 */
class JevException extends \RuntimeException
{
}
