<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Service\Settings\ConfigStore;
use Symfony\Component\Lock\LockFactory;

/**
 * A queue delivers a message at least once: a worker killed before it
 * acknowledges, a visibility timeout, a manual replay. A pass costs money, so
 * each request is taken once: queued when asked for, claimed before it runs,
 * kept as done after, and given back only when it failed, so that a replay
 * from the failed jobs runs.
 *
 * A request still queued counts as a pass in progress (the screen must not
 * offer a second click while the worker has not started), but only for
 * {@see QUEUED_HOURS}: a message that never arrives must not block the module.
 *
 * The last claims are kept in the module settings; the oldest fall off.
 */
final readonly class PassClaims
{
    public const QUEUED_HOURS = 6;

    private const KEY = 'pass_claims';
    private const KEPT = 50;

    public function __construct(
        private ConfigStore $store,
        private LockFactory $lockFactory,
    ) {
    }

    public function queue(string $passId, ?\DateTimeImmutable $now = null): void
    {
        $this->update(static fn (array $claims): array => \array_slice($claims + [$passId => ['state' => 'queued', 'at' => ($now ?? new \DateTimeImmutable())->getTimestamp()]], -self::KEPT, null, true));
    }

    /**
     * True when this call owns the request; false when it was already taken.
     */
    public function claim(string $passId, ?\DateTimeImmutable $now = null): bool
    {
        return $this->update(static function (array $claims) use ($passId, $now): ?array {
            if (isset($claims[$passId]) && $claims[$passId]['state'] !== 'queued') {
                return null;
            }

            $claims[$passId] = ['state' => 'started', 'at' => ($now ?? new \DateTimeImmutable())->getTimestamp()];

            return \array_slice($claims, -self::KEPT, null, true);
        });
    }

    public function finish(string $passId): void
    {
        $this->update(static function (array $claims) use ($passId): array {
            $claims[$passId] = ['state' => 'done', 'at' => $claims[$passId]['at'] ?? time()];

            return $claims;
        });
    }

    public function release(string $passId): void
    {
        $this->update(static function (array $claims) use ($passId): array {
            unset($claims[$passId]);

            return $claims;
        });
    }

    public function hasQueued(?\DateTimeImmutable $now = null): bool
    {
        $since = ($now ?? new \DateTimeImmutable())->modify('-'.self::QUEUED_HOURS.' hours')->getTimestamp();

        foreach ($this->read() as $claim) {
            if ($claim['state'] === 'queued' && $claim['at'] > $since) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, array{state: string, at: int}>
     */
    private function read(): array
    {
        $decoded = json_decode($this->store->get(self::KEY), true);

        return \is_array($decoded) ? array_filter($decoded, static fn (mixed $claim): bool => \is_array($claim) && \is_string($claim['state'] ?? null) && \is_int($claim['at'] ?? null)) : [];
    }

    /**
     * @param \Closure(array<string, array{state: string, at: int}>): ?array<string, array{state: string, at: int}> $change null leaves the claims as they are and answers false
     */
    private function update(\Closure $change): bool
    {
        $lock = $this->lockFactory->createLock('pricewatch.pass-claims');
        $lock->acquire(true);

        try {
            $changed = $change($this->read());

            if ($changed === null) {
                return false;
            }

            $this->store->set(self::KEY, json_encode($changed, \JSON_THROW_ON_ERROR));

            return true;
        } finally {
            $lock->release();
        }
    }
}
