<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Run;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Run\PassItems;

final class PassItemsTest extends TestCase
{
    public function testTheProductsStillSearchedComeFirst(): void
    {
        $json = (string) json_encode([
            self::item(1, PassItems::NO_CARD),
            self::item(2, PassItems::DONE, 0),
            self::item(3, PassItems::QUEUED),
            self::item(4, PassItems::DONE, 2),
            self::item(5, PassItems::TITLE),
            self::item(6, PassItems::ERROR),
            self::item(7, PassItems::SELLERS),
        ]);

        $shown = PassItems::forDisplay($json, 10);

        self::assertSame([5, 7, 4, 3, 1, 2, 6], array_column($shown['items'], 'id'), 'searched, then with offers, then waiting, then the rest; the pass order within');
        self::assertSame(0, $shown['more']);
        self::assertSame(1, $shown['counts']['none'], 'done without a watched competitor counts apart');
        self::assertSame(1, $shown['counts'][PassItems::DONE]);
    }

    public function testALongListIsCut(): void
    {
        $json = (string) json_encode(array_map(static fn (int $id): array => self::item($id, PassItems::QUEUED), range(1, 30)));

        $shown = PassItems::forDisplay($json, 12);

        self::assertCount(12, $shown['items']);
        self::assertSame(18, $shown['more']);
    }

    public function testNothingToShowFromAnUnreadableValue(): void
    {
        self::assertSame([], PassItems::forDisplay(null, 12)['items']);
        self::assertSame([], PassItems::forDisplay('not json', 12)['items']);
        self::assertSame([], PassItems::forDisplay('[{"no":"id"}, 3]', 12)['items']);
    }

    /**
     * @return array{id: int, title: string, sizes: list<string>, status: string, offers: int}
     */
    private static function item(int $id, string $status, int $offers = 0): array
    {
        return ['id' => $id, 'title' => 'Product '.$id, 'sizes' => ['M'], 'status' => $status, 'offers' => $offers];
    }
}
