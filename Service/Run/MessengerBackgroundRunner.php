<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Thelia\Messenger\Transport\ConfiguredQueues;

/**
 * A pass waits on DataForSEO for a minute or more: far too long for a web
 * request. It goes on the queue of the heavy jobs of the shop (exports,
 * imports), where a worker takes it: `messenger:consume async_heavy`.
 * Without a queue configured the transport is synchronous.
 */
final readonly class MessengerBackgroundRunner implements BackgroundRunner
{
    public const TRANSPORT = 'async_heavy';

    public function __construct(
        private MessageBusInterface $bus,
        private ConfiguredQueues $queues,
        private PassClaims $claims,
    ) {
    }

    public function start(RunPass $pass): void
    {
        $this->claims->queue($pass->passId);
        $this->bus->dispatch($pass, [new TransportNamesStamp([self::TRANSPORT])]);
    }

    public function hasPending(): bool
    {
        return $this->claims->hasQueued();
    }

    public function runsInline(): bool
    {
        return $this->queues->heavyJobsRunInline();
    }
}
