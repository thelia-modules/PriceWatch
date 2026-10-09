<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use Propel\Runtime\Exception\PropelException;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Proves the module's schema is usable from Propel, that the strict typing
 * traps are real, and that these tests leave no trace in the development
 * database.
 */
final class ProductIdentitySchemaTest extends PriceWatchIntegrationTestCase
{
    private const MARKER_MPN = 'PRICEWATCH-ISOLATION-MARKER';

    public function testIdentityRowCanBeWrittenAndReadBack(): void
    {
        $saleElementId = $this->createSaleElementId();

        $identity = new PwProductIdentity();
        $identity
            ->setProductSaleElementsId($saleElementId)
            ->setProductId($this->productIdOf($saleElementId))
            ->setGtinRaw('3660-600 123454')
            ->setGtin('03660600123454')
            // TINYINT columns take an int setter: 1, never true.
            ->setGtinValid(1)
            ->setGtinShared(0)
            ->setMpnNormalized(self::MARKER_MPN)
            ->setBrandNormalized('ixon')
            ->setSizeNormalized('L')
            // DECIMAL columns take a string setter: '349.000000', never a float.
            ->setTargetPrice('349.000000')
            ->setCurrencyCode('EUR')
            ->setMonitored(1)
            ->save();

        $reloaded = PwProductIdentityQuery::create()
            ->filterByProductSaleElementsId($saleElementId)
            ->findOne();

        self::assertInstanceOf(PwProductIdentity::class, $reloaded);
        self::assertSame('03660600123454', $reloaded->getGtin());
        self::assertSame(1, $reloaded->getGtinValid());
        self::assertSame('349.000000', $reloaded->getTargetPrice(), 'a DECIMAL column reads back as a string');
        self::assertNotNull($reloaded->getCreatedAt(), 'the timestampable behavior is active');
    }

    public function testTinyIntSetterRefusesABoolean(): void
    {
        $this->expectException(\TypeError::class);

        (new PwProductIdentity())->setGtinValid(true);
    }

    public function testDecimalSetterRefusesAFloat(): void
    {
        $this->expectException(\TypeError::class);

        (new PwProductIdentity())->setTargetPrice(349.0);
    }

    public function testOneIdentityPerSaleElementIsEnforcedByTheDatabase(): void
    {
        $saleElementId = $this->createSaleElementId();

        $first = new PwProductIdentity();
        $first->setProductSaleElementsId($saleElementId)->setProductId($this->productIdOf($saleElementId))->save();

        $second = new PwProductIdentity();
        $second->setProductSaleElementsId($saleElementId)->setProductId($this->productIdOf($saleElementId));

        $this->expectException(PropelException::class);

        $second->save();
    }

    public function testForeignKeyRefusesAnUnknownSaleElement(): void
    {
        $identity = new PwProductIdentity();
        $identity->setProductSaleElementsId(2_000_000_000)->setProductId(1);

        $this->expectException(PropelException::class);

        $identity->save();
    }

    /**
     * Declared after the writing tests on purpose: PHPUnit runs tests in
     * declaration order, so this one observes the state they left behind.
     * Nothing, if the transaction rollback does its job.
     */
    public function testNothingWrittenByTheTestsAboveSurvives(): void
    {
        // The marker is deliberately implausible as real catalogue data: a
        // realistic value would collide with rows a genuine import left behind
        // and this test would stop proving anything about isolation.
        $leftovers = PwProductIdentityQuery::create()
            ->filterByMpnNormalized(self::MARKER_MPN)
            ->count();

        self::assertSame(0, $leftovers, 'the development database must come out of the suite untouched');
    }

    private function createSaleElementId(): int
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product(
            $factory->category(),
            $factory->taxRule(),
            $factory->currency(),
        );

        // Product::create() already made the default sale element.
        $saleElement = ProductSaleElementsQuery::create()
            ->filterByProductId($product->getId())
            ->findOne();

        self::assertNotNull($saleElement, 'the fixture must come with its default sale element');

        return $saleElement->getId();
    }

    private function productIdOf(int $saleElementId): int
    {
        $saleElement = ProductSaleElementsQuery::create()->findPk($saleElementId);

        self::assertNotNull($saleElement);

        return $saleElement->getProductId();
    }
}
