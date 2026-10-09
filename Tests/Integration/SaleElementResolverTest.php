<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Integration;

use PriceWatch\Service\Import\PairListParser;
use PriceWatch\Service\Import\ReferenceRow;
use PriceWatch\Service\Import\ResolutionCode;
use PriceWatch\Service\Import\ResolutionMethod;
use PriceWatch\Service\Import\RowValidator;
use PriceWatch\Service\Import\SaleElementResolver;
use PriceWatch\Service\Normalizer\BrandNormalizer;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Normalizer\GtinNormalizer;
use PriceWatch\Service\Normalizer\MpnNormalizer;
use PriceWatch\Service\Normalizer\SizeNormalizer;
use PriceWatch\Service\Normalizer\TextNormalizer;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeCombination;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\ProductSaleElementsQuery;

final class SaleElementResolverTest extends PriceWatchIntegrationTestCase
{
    private SaleElementResolver $resolver;
    private RowValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $text = new TextNormalizer();
        $gtin = new GtinNormalizer();
        $this->resolver = new SaleElementResolver($gtin, $text);
        $this->validator = new RowValidator(
            $gtin,
            new MpnNormalizer(),
            new BrandNormalizer($text),
            new SizeNormalizer($text),
            new DomainNormalizer(),
            $text,
            new PairListParser(),
        );
    }

    public function testUnknownProductRefIsRefusedAndNothingIsCreated(): void
    {
        $resolution = $this->resolver->resolve($this->row(['product_ref' => 'NO-SUCH-REF-'.uniqid()]));

        self::assertFalse($resolution->isAttached());
        self::assertSame(ResolutionCode::UnknownProductRef, $resolution->failure);
    }

    public function testExplicitSaleElementIdWins(): void
    {
        $product = $this->product();
        $second = $this->addSaleElement($product, 'SECOND');

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'pse_id' => (string) $second->getId(),
        ]));

        self::assertSame((int) $second->getId(), $resolution->saleElementId);
        self::assertSame(ResolutionMethod::PseId, $resolution->method);
    }

    public function testSaleElementIdBelongingToAnotherProductIsRefused(): void
    {
        $first = $this->product();
        $second = $this->product();

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $first->getRef(),
            'pse_id' => (string) $this->defaultSaleElement($second)->getId(),
        ]));

        self::assertFalse($resolution->isAttached());
        self::assertSame(ResolutionCode::PseIdMismatch, $resolution->failure);
    }

    public function testUnknownSaleElementIdIsRefused(): void
    {
        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $this->product()->getRef(),
            'pse_id' => '2000000000',
        ]));

        self::assertSame(ResolutionCode::PseIdNotFound, $resolution->failure);
    }

    public function testGtinAttachesWhenItIsUniqueWithinTheProduct(): void
    {
        $product = $this->product();
        $target = $this->addSaleElement($product, 'L', '3660600123454');
        $this->addSaleElement($product, 'XL', '3660600123461');

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'ean_code' => '3660-600 123454',
        ]));

        self::assertSame((int) $target->getId(), $resolution->saleElementId);
        self::assertSame(ResolutionMethod::Gtin, $resolution->method);
    }

    public function testSharedGtinAloneCannotDesignateASaleElement(): void
    {
        $product = $this->product();
        $this->addSaleElement($product, 'L', '3660600123454');
        $this->addSaleElement($product, 'XL', '3660600123454');

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'ean_code' => '3660600123454',
        ]));

        self::assertFalse($resolution->isAttached());
        self::assertSame(ResolutionCode::GtinShared, $resolution->failure);
    }

    public function testSharedGtinStillResolvesWhenAttributesSettleIt(): void
    {
        // The cascade must not stop at an ambiguous rung.
        $product = $this->product();
        $size = $this->attribute('Taille');
        $large = $this->attributeValue($size, 'L');
        $extraLarge = $this->attributeValue($size, 'XL');

        $target = $this->addSaleElement($product, 'L', '3660600123454');
        $other = $this->addSaleElement($product, 'XL', '3660600123454');
        $this->combine($target, $size, $large);
        $this->combine($other, $size, $extraLarge);

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'ean_code' => '3660600123454',
            'attribute_combination' => 'Taille:L',
        ]));

        self::assertSame((int) $target->getId(), $resolution->saleElementId);
        self::assertSame(ResolutionMethod::Attributes, $resolution->method);
    }

    public function testAttributeCombinationMustMatchExactly(): void
    {
        $product = $this->product();
        $size = $this->attribute('Taille');
        $colour = $this->attribute('Coloris');
        $large = $this->attributeValue($size, 'L');
        $black = $this->attributeValue($colour, 'Noir');

        $target = $this->addSaleElement($product, 'L-NOIR');
        $this->combine($target, $size, $large);
        $this->combine($target, $colour, $black);

        $partial = $this->addSaleElement($product, 'L-ONLY');
        $this->combine($partial, $size, $large);

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'attribute_combination' => 'Coloris:Noir|Taille:L',
        ]));

        self::assertSame((int) $target->getId(), $resolution->saleElementId, 'a subset must not match');
        self::assertSame(ResolutionMethod::Attributes, $resolution->method);
    }

    public function testUnderSpecifiedCombinationIsRefusedRatherThanNarrowedDown(): void
    {
        // The file states only the size; one sale element happens to carry that
        // size plus a colour, the other carries a different size. A permissive
        // subset match would attach the line to the "L black" variant without
        // anyone having asked for black. Exact matching refuses instead.
        $product = $this->product();
        $size = $this->attribute('Taille');
        $colour = $this->attribute('Coloris');
        $large = $this->attributeValue($size, 'L');
        $extraLarge = $this->attributeValue($size, 'XL');
        $black = $this->attributeValue($colour, 'Noir');

        $largeBlack = $this->addSaleElement($product, 'L-NOIR');
        $this->combine($largeBlack, $size, $large);
        $this->combine($largeBlack, $colour, $black);

        $extraLargeOnly = $this->addSaleElement($product, 'XL');
        $this->combine($extraLargeOnly, $size, $extraLarge);

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'attribute_combination' => 'Taille:L',
        ]));

        self::assertFalse($resolution->isAttached());
        self::assertSame(ResolutionCode::AmbiguousPse, $resolution->failure);
    }

    public function testAttributeLabelsAreMatchedIgnoringCaseAndAccents(): void
    {
        $product = $this->product();
        $size = $this->attribute('Taille');
        $large = $this->attributeValue($size, 'Large');
        $target = $this->addSaleElement($product, 'L');
        $this->combine($target, $size, $large);
        $this->addSaleElement($product, 'OTHER');

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'attribute_combination' => 'TAILLE:large',
        ]));

        self::assertSame((int) $target->getId(), $resolution->saleElementId);
    }

    public function testUnknownAttributeLabelIsReportedAsSuch(): void
    {
        $product = $this->product();
        $size = $this->attribute('Taille');
        $this->combine($this->addSaleElement($product, 'L'), $size, $this->attributeValue($size, 'L'));
        $this->addSaleElement($product, 'XL');

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'attribute_combination' => 'Pointure:42',
        ]));

        self::assertFalse($resolution->isAttached());
        self::assertSame(ResolutionCode::AttributeUnresolved, $resolution->failure);
    }

    public function testSaleElementRefAttachesWhenUniqueWithinTheProduct(): void
    {
        $product = $this->product();
        $target = $this->addSaleElement($product, 'UNIQUE-REF');
        $this->addSaleElement($product, 'OTHER-REF');

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'pse_ref' => $target->getRef(),
        ]));

        self::assertSame((int) $target->getId(), $resolution->saleElementId);
        self::assertSame(ResolutionMethod::PseRef, $resolution->method);
    }

    public function testDuplicateSaleElementRefIsRefused(): void
    {
        // pse_ref is indexed but not unique in the core schema.
        $product = $this->product();
        $this->addSaleElement($product, 'SAME-REF');
        $this->addSaleElement($product, 'SAME-REF');

        $resolution = $this->resolver->resolve($this->row([
            'product_ref' => $product->getRef(),
            'pse_ref' => 'SAME-REF',
        ]));

        self::assertFalse($resolution->isAttached());
        self::assertSame(ResolutionCode::AmbiguousPseRef, $resolution->failure);
    }

    public function testSingleSaleElementProductNeedsNoKeyAtAll(): void
    {
        $product = $this->product();

        $resolution = $this->resolver->resolve($this->row(['product_ref' => $product->getRef()]));

        self::assertSame((int) $this->defaultSaleElement($product)->getId(), $resolution->saleElementId);
        self::assertSame(ResolutionMethod::OnlySaleElement, $resolution->method);
    }

    public function testMultiVariantProductWithoutAnyKeyIsRefusedRatherThanGuessed(): void
    {
        $product = $this->product();
        $this->addSaleElement($product, 'L');

        $resolution = $this->resolver->resolve($this->row(['product_ref' => $product->getRef()]));

        self::assertFalse($resolution->isAttached());
        self::assertSame(ResolutionCode::AmbiguousPse, $resolution->failure);
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, string> $columns
     */
    private function row(array $columns): ReferenceRow
    {
        $row = $this->validator->validate(2, $columns, implode(';', $columns));

        self::assertInstanceOf(ReferenceRow::class, $row, 'the fixture row must be well formed');

        return $row;
    }

    private function product(): Product
    {
        $factory = $this->createFixtureFactory();

        return $factory->product($factory->category(), $factory->taxRule(), $factory->currency());
    }

    private function defaultSaleElement(Product $product): ProductSaleElements
    {
        $saleElement = ProductSaleElementsQuery::create()->filterByProductId($product->getId())->orderById()->findOne();

        self::assertNotNull($saleElement);

        return $saleElement;
    }

    private function addSaleElement(Product $product, string $ref, ?string $eanCode = null): ProductSaleElements
    {
        $saleElement = $this->createFixtureFactory()->productSaleElement($product, ['ref' => $ref]);

        if ($eanCode !== null) {
            $saleElement->setEanCode($eanCode)->save();
        }

        return $saleElement;
    }

    private function attribute(string $title): Attribute
    {
        return $this->createFixtureFactory()->attribute(['title' => $title, 'locale' => 'fr_FR']);
    }

    private function attributeValue(Attribute $attribute, string $title): AttributeAv
    {
        return $this->createFixtureFactory()->attributeAv($attribute, ['title' => $title, 'locale' => 'fr_FR']);
    }

    private function combine(ProductSaleElements $saleElement, Attribute $attribute, AttributeAv $value): void
    {
        (new AttributeCombination())
            ->setProductSaleElementsId($saleElement->getId())
            ->setAttributeId($attribute->getId())
            ->setAttributeAvId($value->getId())
            ->save();
    }
}
