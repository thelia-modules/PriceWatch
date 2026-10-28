<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Service\Settings\ConfigStore;
use Symfony\Component\Lock\LockFactory;

/**
 * A pass asked for from the back office in a shop where no worker takes the
 * queue of the background jobs. A web request cannot run it (it lasts
 * minutes), so the request is written down, in the database, and the next
 * call of the scheduled task (`pricewatch:scheduled`, hourly) runs it.
 *
 * One request at a time: asking again while one waits changes nothing. The
 * request moves pending, running, then done or failed; {@see take()} is the
 * only way to start it and does so under a lock, so two calls of the task
 * never run the same request. A request left running for {@see STALE_HOURS}
 * is a task that died: it stops blocking the next one.
 */
final readonly class PassRequests
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    private const KEY = 'pass_request';
    private const STALE_HOURS = 6;

    public function __construct(
        private ConfigStore $store,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @param list<string> $productRefs
     *
     * @return string the id of the request, which becomes the id of the pass
     *
     * @throws PassAlreadyRequested when a request already waits or runs
     */
    public function request(int $variants, array $productRefs, bool $priority, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable();
        $id = bin2hex(random_bytes(8));

        $this->update(function (?array $current) use ($variants, $productRefs, $priority, $now, $id): array {
            if ($current !== null && $this->occupies($current, $now)) {
                throw new PassAlreadyRequested();
            }

            return ['id' => $id, 'state' => self::PENDING, 'at' => $now->getTimestamp(), 'variants' => $variants, 'productRefs' => $productRefs, 'priority' => $priority];
        });

        return $id;
    }

    /**
     * The pending request, now marked running; null when there is none or
     * another call took it first.
     *
     * @return array{id: string, variants: int, productRefs: list<string>, priority: bool}|null
     */
    public function take(?\DateTimeImmutable $now = null): ?array
    {
        $taken = null;

        $this->update(static function (?array $current) use (&$taken, $now): ?array {
            if ($current === null || $current['state'] !== self::PENDING) {
                return $current;
            }

            $taken = ['id' => $current['id'], 'variants' => $current['variants'], 'productRefs' => $current['productRefs'], 'priority' => $current['priority']];

            return [...$current, 'state' => self::RUNNING, 'at' => ($now ?? new \DateTimeImmutable())->getTimestamp()];
        });

        return $taken;
    }

    public function finish(string $id): void
    {
        $this->settle($id, self::DONE);
    }

    public function fail(string $id): void
    {
        $this->settle($id, self::FAILED);
    }

    /**
     * Gives a taken request back, when another pass was running: it waits for
     * the next call.
     */
    public function release(string $id): void
    {
        $this->settle($id, self::PENDING);
    }

    /**
     * @return array{id: string, state: string, at: int, variants: int, productRefs: list<string>, priority: bool}|null
     */
    public function current(): ?array
    {
        return $this->read();
    }

    private function settle(string $id, string $state): void
    {
        $this->update(static fn (?array $current): ?array => $current !== null && $current['id'] === $id ? [...$current, 'state' => $state] : $current);
    }

    /**
     * @param array{state: string, at: int} $request
     */
    private function occupies(array $request, \DateTimeImmutable $now): bool
    {
        if ($request['state'] === self::PENDING) {
            return true;
        }

        return $request['state'] === self::RUNNING && $request['at'] > $now->modify('-'.self::STALE_HOURS.' hours')->getTimestamp();
    }

    /**
     * @return array{id: string, state: string, at: int, variants: int, productRefs: list<string>, priority: bool}|null
     */
    private function read(): ?array
    {
        $decoded = json_decode($this->store->get(self::KEY), true);

        if (!\is_array($decoded) || !\is_string($decoded['id'] ?? null) || !\is_string($decoded['state'] ?? null) || !\is_int($decoded['at'] ?? null)) {
            return null;
        }

        return [
            'id' => $decoded['id'],
            'state' => $decoded['state'],
            'at' => $decoded['at'],
            'variants' => (int) ($decoded['variants'] ?? 0),
            'productRefs' => array_values(array_filter((array) ($decoded['productRefs'] ?? []), 'is_string')),
            'priority' => (bool) ($decoded['priority'] ?? true),
        ];
    }

    /**
     * @param \Closure(?array<string, mixed>): ?array<string, mixed> $change
     */
    private function update(\Closure $change): void
    {
        $lock = $this->lockFactory->createLock('pricewatch.pass-request');
        $lock->acquire(true);

        try {
            $changed = $change($this->read());
            $this->store->set(self::KEY, $changed === null ? '' : json_encode($changed, \JSON_THROW_ON_ERROR));
        } finally {
            $lock->release();
        }
    }
}
