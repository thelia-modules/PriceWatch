<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Run;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Run\PassClaims;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class PassClaimsTest extends TestCase
{
    private InMemoryConfigStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigStore();
    }

    public function testARequestIsTakenOnce(): void
    {
        self::assertTrue($this->claims()->claim('a'));
        self::assertFalse($this->claims()->claim('a'), 'a second delivery while it runs');

        $this->claims()->finish('a');

        self::assertFalse($this->claims()->claim('a'), 'a delivery after it ended');
    }

    public function testAFailedRequestCanBeReplayed(): void
    {
        $this->claims()->claim('a');
        $this->claims()->release('a');

        self::assertTrue($this->claims()->claim('a'));
    }

    public function testAQueuedRequestIsPendingUntilItIsTaken(): void
    {
        $now = new \DateTimeImmutable('2026-10-28 10:00:00');
        $this->claims()->queue('a', $now);

        self::assertTrue($this->claims()->hasQueued($now->modify('+5 hours')));
        self::assertFalse($this->claims()->hasQueued($now->modify('+7 hours')), 'a message that never arrives must not block the module');
        self::assertTrue($this->claims()->claim('a', $now), 'queued then taken by the worker');
        self::assertFalse($this->claims()->hasQueued($now));
    }

    public function testOnlyTheLastClaimsAreKept(): void
    {
        for ($i = 0; $i < 60; ++$i) {
            $this->claims()->claim('p'.$i);
        }

        self::assertCount(50, json_decode($this->store->get('pass_claims'), true));
        self::assertTrue($this->claims()->claim('p0'), 'forgotten: the oldest fell off');
        self::assertFalse($this->claims()->claim('p59'));
    }

    public function testAnUnreadableStoreStartsFresh(): void
    {
        $this->store->set('pass_claims', 'not json');

        self::assertTrue($this->claims()->claim('a'));
    }

    private function claims(): PassClaims
    {
        return new PassClaims($this->store, new LockFactory(new InMemoryStore()));
    }
}
