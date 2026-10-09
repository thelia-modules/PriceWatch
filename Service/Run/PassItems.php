<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Model\PwCollectRun;

/**
 * The products a collect pass is working on, each with where it stands,
 * written on the pass's row so the back office can list them under the
 * progress bar.
 */
final class PassItems
{
    public const QUEUED = 'queued';
    public const GTIN = 'gtin';
    public const TITLE = 'title';
    public const SELLERS = 'sellers';
    public const DONE = 'done';
    public const NO_CARD = 'no_card';
    public const ERROR = 'error';

    /** Still being worked on: listed first. */
    private const ACTIVE = [self::GTIN, self::TITLE, self::SELLERS];

    /** @var array<int, array{id: int, title: string, sizes: list<string>, status: string, offers: int}> product id => item */
    private array $items = [];

    public function add(int $productId, string $title, ?string $size): void
    {
        $this->items[$productId] ??= ['id' => $productId, 'title' => $title, 'sizes' => [], 'status' => self::QUEUED, 'offers' => 0];

        if ($size !== null && $size !== '' && !\in_array($size, $this->items[$productId]['sizes'], true)) {
            $this->items[$productId]['sizes'][] = $size;
        }
    }

    /**
     * @param list<int>|null $productIds null for every product
     */
    public function set(string $status, ?array $productIds = null): void
    {
        foreach ($productIds ?? array_keys($this->items) as $productId) {
            if (isset($this->items[$productId])) {
                $this->items[$productId]['status'] = $status;
            }
        }
    }

    public function offerRecorded(int $productId): void
    {
        if (isset($this->items[$productId])) {
            ++$this->items[$productId]['offers'];
        }
    }

    public function status(int $productId): ?string
    {
        return $this->items[$productId]['status'] ?? null;
    }

    /**
     * Saves only when something changed, like the progress counters.
     */
    public function save(PwCollectRun $run): void
    {
        $run->setProgressItems((string) json_encode(array_values($this->items), \JSON_UNESCAPED_UNICODE));

        if ($run->isModified()) {
            $run->save();
        }
    }

    /**
     * What the back office shows: the products still being searched first,
     * then those with offers, then the rest; at most $limit of them.
     *
     * @return array{items: list<array{id: int, title: string, sizes: list<string>, status: string, offers: int}>, more: int, counts: array<string, int>}
     */
    public static function forDisplay(?string $json, int $limit): array
    {
        $items = json_decode((string) $json, true);
        $items = \is_array($items) ? array_values(array_filter($items, static fn (mixed $item): bool => \is_array($item) && isset($item['id'], $item['title'], $item['status']))) : [];

        $rank = static fn (array $item): int => match (true) {
            \in_array($item['status'], self::ACTIVE, true) => 0,
            $item['status'] === self::DONE && ($item['offers'] ?? 0) > 0 => 1,
            $item['status'] === self::QUEUED => 2,
            default => 3,
        };
        // Within a rank, the order the pass took them in.
        uksort($items, static fn (int $a, int $b): int => [$rank($items[$a]), $a] <=> [$rank($items[$b]), $b]);

        $counts = [];

        foreach ($items as $item) {
            // Done without a whitelisted seller is not the same news as done with offers.
            $key = $item['status'] === self::DONE && ($item['offers'] ?? 0) === 0 ? 'none' : $item['status'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return [
            'items' => array_values(\array_slice($items, 0, max(0, $limit))),
            'more' => max(0, \count($items) - $limit),
            'counts' => $counts,
        ];
    }
}
