<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Model\PwProductIdentity;
use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Import\IdentityUpserter;
use PriceWatch\Service\Import\PairListParser;
use PriceWatch\Service\Import\RowValidator;
use PriceWatch\Service\Import\SaleElementResolver;
use PriceWatch\Service\Import\SharedGtinFlags;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\MpnNormalizer;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use PriceWatch\Service\Referential\CatalogVariantRepository;
use PriceWatch\Service\Referential\ProductEnrolment;
use Thelia\Model\AttributeCombination;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Adding a product from its page: the catalogue goes through the reference
 * file's own checks, and what an import brought is never overwritten.
 */
final class ProductEnrolmentTest extends PriceWatchIntegrationTestCase
{
    public function testEveryVariantIsAddedFromTheCatalogue(): void
    {
        [$product, $medium, $large] = $this->productWithSizes();

        $report = $this->enrolment()->enrol((int) $product->getId());

        self::assertSame(2, $report->added);
        self::assertSame([], $report->refused);

        $identity = $this->identity($medium);
        self::assertSame(1, $identity->getMonitored());
        self::assertSame(1, $identity->getGtinValid());
        self::assertSame(str_pad((string) $medium->getEanCode(), 14, '0', \STR_PAD_LEFT), $identity->getGtin(), 'normalised as an import would');
        self::assertSame('M', $identity->getSizeLabel(), 'size read from the catalogue\'s attribute, as an import would');
        self::assertSame('L', $this->identity($large)->getSizeLabel());
        self::assertSame('catalog', $identity->getGtinSource(), 'the GTIN came from the catalogue, not from a file');
    }

    public function testAWrongEanInTheCatalogueIsKeptToBeFixed(): void
    {
        [$product, $medium] = $this->productWithSizes();
        $medium->setEanCode('3660600123455')->save();

        $this->enrolment()->enrol((int) $product->getId());

        $identity = $this->identity($medium);
        self::assertSame(0, $identity->getGtinValid(), 'wrong check digit: never searched');
        self::assertSame('3660600123455', $identity->getGtinRaw(), 'shown on the card as invalid, so someone fixes it');
    }

    public function testWhatAnImportBroughtIsNeverOverwritten(): void
    {
        [$product, $medium] = $this->productWithSizes();
        (new PwProductIdentity())
            ->setProductSaleElementsId($medium->getId())
            ->setProductId($product->getId())
            ->setMpnNormalized('IXON100101045M')
            ->setTargetPrice('349.000000')
            ->setMonitored(0)
            ->save();

        $report = $this->enrolment()->enrol((int) $product->getId());

        self::assertSame(1, $report->added, 'only the variant missing from the referential');
        self::assertSame(1, $report->resumed, 'the paused one is watched again');
        $identity = $this->identity($medium);
        self::assertSame(1, $identity->getMonitored());
        self::assertSame('IXON100101045M', $identity->getMpnNormalized());
        self::assertSame('349.000000', $identity->getTargetPrice());
    }

    public function testAddingTwiceChangesNothing(): void
    {
        [$product] = $this->productWithSizes();
        $this->enrolment()->enrol((int) $product->getId());

        $again = $this->enrolment()->enrol((int) $product->getId());

        self::assertSame([0, 0, 2], [$again->added, $again->resumed, $again->alreadyMonitored]);
    }

    public function testAGtinCarriedByTwoVariantsIsFlaggedShared(): void
    {
        [$product, $medium, $large] = $this->productWithSizes();
        $large->setEanCode($medium->getEanCode())->save();

        $this->enrolment()->enrol((int) $product->getId());

        self::assertSame(1, $this->identity($medium)->getGtinShared(), 'a shared GTIN designates no variant: never searched');
        self::assertSame(1, $this->identity($large)->getGtinShared());
    }

    public function testPausingAndResumingKeepsTheIdentities(): void
    {
        [$product, $medium, $large] = $this->productWithSizes();
        $this->enrolment()->enrol((int) $product->getId());

        self::assertSame(1, $this->enrolment()->setMonitored((int) $product->getId(), false, (int) $medium->getId()));
        self::assertSame(0, $this->identity($medium)->getMonitored());
        self::assertSame(1, $this->identity($large)->getMonitored(), 'one variant only');

        self::assertSame(1, $this->enrolment()->setMonitored((int) $product->getId(), false), 'the rest of the product');
        self::assertSame(2, $this->enrolment()->setMonitored((int) $product->getId(), true));
        self::assertSame(2, PwProductIdentityQuery::create()->filterByProductId($product->getId())->filterByMonitored(1)->count());
    }

    public function testAVariantOfAnotherProductCannotBeTouched(): void
    {
        [$product] = $this->productWithSizes();
        [$other, $otherMedium] = $this->productWithSizes();
        $this->enrolment()->enrol((int) $other->getId());

        self::assertSame(0, $this->enrolment()->setMonitored((int) $product->getId(), false, (int) $otherMedium->getId()));
        self::assertSame(1, $this->identity($otherMedium)->getMonitored());
    }

    // ------------------------------------------------------------------

    /**
     * @return array{0: Product, 1: ProductSaleElements, 2: ProductSaleElements}
     */
    private function productWithSizes(): array
    {
        $factory = $this->createFixtureFactory();
        $product = $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
        $size = $factory->attribute(['title' => 'Taille vêtement', 'locale' => 'fr_FR']);
        $medium = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->findOne();
        self::assertNotNull($medium);
        $medium->setEanCode($this->ean())->save();
        $large = (new ProductSaleElements())
            ->setProductId($product->getId())
            ->setRef('PW-ENROL-'.bin2hex(random_bytes(4)))
            ->setQuantity(1)
            ->setIsDefault(false)
            ->setEanCode($this->ean());
        $large->save();

        foreach ([[$medium, 'M'], [$large, 'L']] as [$saleElement, $label]) {
            $value = $factory->attributeAv($size, ['title' => $label, 'locale' => 'fr_FR']);
            (new AttributeCombination())
                ->setProductSaleElementsId($saleElement->getId())
                ->setAttributeId($size->getId())
                ->setAttributeAvId($value->getId())
                ->save();
        }

        return [$product, $medium, $large];
    }

    /**
     * A valid EAN-13 in the 2xx in-store range: never a real product's.
     */
    private function ean(): string
    {
        $body = '2'.str_pad((string) random_int(0, 99999999999), 11, '0', \STR_PAD_LEFT);
        $sum = 0;

        foreach (str_split(strrev($body)) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 === 0 ? 3 : 1);
        }

        return $body.((10 - $sum % 10) % 10);
    }

    private function identity(ProductSaleElements $saleElement): PwProductIdentity
    {
        $identity = PwProductIdentityQuery::create()->filterByProductSaleElementsId($saleElement->getId())->findOne();
        self::assertNotNull($identity);

        return $identity;
    }

    private function enrolment(): ProductEnrolment
    {
        $text = new TextNormalizer();
        $gtin = new GtinNormalizer();

        return new ProductEnrolment(
            new CatalogVariantRepository(),
            new RowValidator($gtin, new MpnNormalizer(), new BrandNormalizer($text), new SizeNormalizer($text), new DomainNormalizer(), $text, new PairListParser()),
            new SaleElementResolver($gtin, $text),
            new IdentityUpserter($gtin, $text),
            new SharedGtinFlags(),
        );
    }
}
