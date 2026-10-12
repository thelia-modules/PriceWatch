<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Direct;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Direct\DirectMatcher;
use PriceWatch\Service\Direct\PageVariant;
use PriceWatch\Service\Direct\ProductPage;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;

final class DirectMatcherTest extends TestCase
{
    private const OURS = [
        11 => ['gtin' => '04530935639908', 'size' => 'XS'],
        12 => ['gtin' => '04530935639915', 'size' => 'S'],
        13 => ['gtin' => null, 'size' => 'M'],
    ];

    public function testAGtinOfOursOnThePageIsAMatchWhateverThePageStatus(): void
    {
        $page = $this->page([new PageVariant('638.70', 'EUR', 'XS', 'Noir Mat', '04530935639908'), new PageVariant('640.00', 'EUR', 'S', 'Noir Mat', '04530935639915')]);

        $matches = $this->matcher()->match($page, false, self::OURS);

        self::assertSame(['11' => ['638.70', 'ean'], '12' => ['640.00', 'ean']], array_map(static fn (array $match): array => [$match['variant']->price, $match['method']], $matches));
    }

    public function testOnAPageThatListsGtinsAVariantWithoutOursIsNotTakenBySize(): void
    {
        $page = $this->page([new PageVariant('638.70', 'EUR', 'XS', null, '04530935639908'), new PageVariant('638.70', 'EUR', 'M', null, '03660600123454')]);

        $matches = $this->matcher()->match($page, true, self::OURS);

        self::assertSame([11], array_keys($matches), 'the M on the page carries another GTIN: another article, whatever its size says');
    }

    public function testOnAConfirmedPageWithoutGtinTheSizeDecides(): void
    {
        $page = $this->page([new PageVariant('638.70', 'EUR', 'XS', 'Matte Black'), new PageVariant('638.70', 'EUR', 'M', 'Matte Black')]);

        $matches = $this->matcher()->match($page, true, self::OURS);

        self::assertSame([11, 13], array_keys($matches));
        self::assertSame('size', $matches[13]['method']);
    }

    public function testAnUnconfirmedPageWithoutGtinGivesNothing(): void
    {
        $page = $this->page([new PageVariant('638.70', 'EUR', 'M', 'Matte Black')]);

        self::assertSame([], $this->matcher()->match($page, false, self::OURS), 'which article it is has not been proven');
    }

    public function testAPageInSeveralColoursGivesNothingBySize(): void
    {
        // M only exists in white here: still not proven to be our colour.
        $page = $this->page([new PageVariant('638.70', 'EUR', 'XS', 'Matte Black'), new PageVariant('659.00', 'EUR', 'M', 'White')]);

        self::assertSame([], $this->matcher()->match($page, true, self::OURS), 'which colour is ours cannot be told');
    }

    public function testAPriceWithoutSizeIsNeverSpreadOverOurSizes(): void
    {
        $page = $this->page([new PageVariant('638.70', 'EUR')]);

        self::assertSame([], $this->matcher()->match($page, true, self::OURS));
    }

    /**
     * @param list<PageVariant> $variants
     */
    private function page(array $variants): ProductPage
    {
        return new ProductPage('test', 'Casque', 'EUR', $variants);
    }

    private function matcher(): DirectMatcher
    {
        return new DirectMatcher(new SizeNormalizer(new TextNormalizer()));
    }
}
