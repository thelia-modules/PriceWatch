<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Run;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Run\PassAlreadyRequested;
use PriceWatch\Service\Run\PassRequests;
use PriceWatch\Tests\Doubles\InMemoryConfigStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class PassRequestsTest extends TestCase
{
    private InMemoryConfigStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryConfigStore();
    }

    public function testARequestIsWrittenDownInTheStore(): void
    {
        $id = $this->requests()->request(50, ['REF1'], true, new \DateTimeImmutable('2026-10-28 10:00:00'));

        $current = $this->requests()->current();
        self::assertSame([$id, PassRequests::PENDING, 50, ['REF1'], true], [$current['id'] ?? null, $current['state'] ?? null, $current['variants'] ?? null, $current['productRefs'] ?? null, $current['priority'] ?? null], 'a new object reads it back: it is not kept in memory');
    }

    public function testAskingAgainWhileOneWaitsChangesNothing(): void
    {
        $first = $this->requests()->request(50, [], true);

        try {
            $this->requests()->request(10, ['OTHER'], true);
            self::fail('a second request must be refused');
        } catch (PassAlreadyRequested) {
        }

        self::assertSame([$first, 50], [$this->requests()->current()['id'] ?? null, $this->requests()->current()['variants'] ?? null]);
    }

    public function testARequestIsTakenOnce(): void
    {
        $id = $this->requests()->request(50, [], true);

        self::assertSame($id, $this->requests()->take()['id'] ?? null);
        self::assertNull($this->requests()->take(), 'the second call of the task finds nothing to run');
        self::assertSame(PassRequests::RUNNING, $this->requests()->current()['state'] ?? null);
    }

    public function testARequestThatRunsBlocksTheNextOneUntilItEndsOrIsStale(): void
    {
        $now = new \DateTimeImmutable('2026-10-28 10:00:00');
        $requests = $this->requests();
        $id = $requests->request(50, [], true, $now);
        $requests->take($now);

        try {
            $requests->request(10, [], true, $now->modify('+1 hour'));
            self::fail('still running');
        } catch (PassAlreadyRequested) {
        }

        self::assertNotSame($id, $requests->request(10, [], true, $now->modify('+7 hours')), 'a task that died does not block for ever');

        $requests->finish($requests->current()['id'] ?? '');
        self::assertSame(PassRequests::DONE, $requests->current()['state'] ?? null);
        self::assertNotSame('', $requests->request(5, [], true), 'done: a new request is allowed');
    }

    public function testAGivenBackRequestWaitsForTheNextCall(): void
    {
        $id = $this->requests()->request(50, [], true);
        $this->requests()->take();

        $this->requests()->release($id);

        self::assertSame($id, $this->requests()->take()['id'] ?? null);
    }

    private function requests(): PassRequests
    {
        return new PassRequests($this->store, new LockFactory(new InMemoryStore()));
    }
}
