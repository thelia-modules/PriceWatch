<?php

declare(strict_types=1);

namespace PriceWatch\Service\Source;

use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Model\Country;
use Thelia\Model\CountryQuery;
use Thelia\Model\Currency;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\ProductSaleElementsQuery;

/**
 * Our price including tax, in euros, as a French shopper sees it.
 *
 * product_price.price is stored excluding tax. ProductSaleElements::getTaxedPrice()
 * cannot be used here: it reads a virtual column that only the front loops
 * add to their query, and throws on a plain findPk(). The prices are read
 * through getPricesByCurrency() instead, which also handles prices derived
 * from the default currency, and the product's tax rule is applied for
 * France. The country is given explicitly: in CLI there is no request.
 */
final class OurPrice
{
    private ?Country $france = null;
    private ?Currency $euro = null;

    public function __construct(
        private readonly TaxCalculatorFactoryInterface $taxCalculatorFactory,
    ) {
    }

    public function inclTax(int $saleElementId): ?string
    {
        $prices = $this->prices($saleElementId);

        return $prices === null ? null : number_format($prices['inclTax'], 2, '.', '');
    }

    /**
     * The same price, with what it is excluding tax and whether it is a
     * promotion: the price proposals reckon the margin excluding tax.
     *
     * @return array{inclTax: float, exclTax: float, promo: bool}|null
     */
    public function prices(int $saleElementId): ?array
    {
        $saleElement = ProductSaleElementsQuery::create()->findPk($saleElementId);
        $country = $this->france ??= CountryQuery::create()->filterByIsoalpha2('FR')->findOne();
        $currency = $this->euro ??= CurrencyQuery::create()->filterByCode('EUR')->findOne();

        if ($saleElement === null || $country === null || $currency === null) {
            return null;
        }

        try {
            $prices = $saleElement->getPricesByCurrency($currency);
        } catch (\RuntimeException) {
            return null;
        }

        $promo = (int) $saleElement->getPromo() === 1;
        $untaxed = $promo ? $prices->getPromoPrice() : $prices->getPrice();
        $taxed = $this->taxCalculatorFactory->createTaxCalculator()
            ->load($saleElement->getProduct(), $country)
            ->getTaxedPrice($untaxed);

        return $taxed > 0 ? ['inclTax' => (float) $taxed, 'exclTax' => (float) $untaxed, 'promo' => $promo] : null;
    }
}
