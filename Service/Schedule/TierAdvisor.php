<?php

declare(strict_types=1);

namespace PriceWatch\Service\Schedule;

/**
 * The tiers proposed from the sales, and the products whose tier differs
 * from its proposal: what "apply the proposals" changes.
 */
final readonly class TierAdvisor
{
    public function __construct(
        private ScheduleRepository $repository,
    ) {
    }

    /**
     * @return array<int, WatchTier> product id => proposed tier, for the watched products the shop shows
     */
    public function proposals(?\DateTimeImmutable $now = null): array
    {
        $productIds = array_map(static fn (ScheduledProduct $product): int => $product->productId, $this->repository->watched());
        $since = ($now ?? new \DateTimeImmutable())->modify('-'.TierProposal::SALES_DAYS.' days');

        return TierProposal::propose($productIds, $this->repository->revenues($productIds, $since));
    }

    /**
     * @return array<int, WatchTier> product id => proposed tier, only where it differs from the current one
     */
    public function changes(?\DateTimeImmutable $now = null): array
    {
        $current = [];

        foreach ($this->repository->watched() as $product) {
            $current[$product->productId] = $product->tier;
        }

        return array_filter(
            $this->proposals($now),
            static fn (WatchTier $tier, int $productId): bool => ($current[$productId] ?? WatchTier::DEFAULT) !== $tier,
            \ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return int how many products changed tier
     */
    public function apply(?\DateTimeImmutable $now = null): int
    {
        $changes = $this->changes($now);

        foreach ($changes as $productId => $tier) {
            $this->repository->setTier($productId, $tier);
        }

        return \count($changes);
    }
}
