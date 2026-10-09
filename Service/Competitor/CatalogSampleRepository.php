<?php

declare(strict_types=1);

namespace PriceWatch\Service\Competitor;

use PriceWatch\Model\PwProductIdentityQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Thelia\Model\BrandQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ProductQuery;

/**
 * Brands come first from the monitored referential, because those are the
 * ones worth comparing, then from the visible catalogue.
 */
final readonly class CatalogSampleRepository implements CatalogSampleSource
{
    private const MAX_BRANDS = 30;
    private const MAX_CATEGORIES = 15;
    private const MAX_PRODUCTS = 20;

    public function sample(string $locale): CatalogSample
    {
        $shopUrl = trim((string) ConfigQuery::read('url_site', ''));

        return new CatalogSample(
            shopUrl: $shopUrl === '' ? null : $shopUrl,
            brands: $this->brands($locale),
            categories: $this->titles(
                CategoryQuery::create()->filterByParent(0)->filterByVisible(1)->orderByPosition()->limit(self::MAX_CATEGORIES)->find(),
                $locale,
            ),
            productTitles: $this->titles(
                ProductQuery::create()->filterByVisible(1)->orderById()->limit(self::MAX_PRODUCTS)->find(),
                $locale,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function brands(string $locale): array
    {
        $brands = [];

        $monitored = PwProductIdentityQuery::create()
            ->filterByMonitored(1)
            ->filterByBrandLabel(null, Criteria::ISNOTNULL)
            ->select(['BrandLabel'])
            ->distinct()
            ->limit(self::MAX_BRANDS)
            ->find();

        foreach ($monitored as $label) {
            $brands[] = (string) $label;
        }

        foreach ($this->titles(BrandQuery::create()->filterByVisible(1)->orderByPosition()->limit(self::MAX_BRANDS)->find(), $locale) as $title) {
            $brands[] = $title;
        }

        return \array_slice(array_values(array_unique(array_filter($brands, static fn (string $b): bool => trim($b) !== ''))), 0, self::MAX_BRANDS);
    }

    /**
     * @param iterable<object> $rows Thelia models carrying the i18n behaviour
     *
     * @return list<string>
     */
    private function titles(iterable $rows, string $locale): array
    {
        $titles = [];

        foreach ($rows as $row) {
            $title = trim((string) $row->setLocale($locale)->getTitle());

            if ($title === '') {
                // Fall back to whatever translation exists rather than drop
                // the row: the model reads any language.
                $title = trim((string) $row->setLocale('en_US')->getTitle());
            }

            if ($title !== '') {
                $titles[] = mb_substr($title, 0, 120);
            }
        }

        return array_values(array_unique($titles));
    }
}
