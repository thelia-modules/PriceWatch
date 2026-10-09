<?php

declare(strict_types=1);

namespace PriceWatch\Service\Referential;

use PriceWatch\Model\PwProductIdentityQuery;
use PriceWatch\Service\Import\IdentityUpserter;
use PriceWatch\Service\Import\ResolutionCode;
use PriceWatch\Service\Import\RowRejection;
use PriceWatch\Service\Import\RowValidator;
use PriceWatch\Service\Import\SaleElementResolver;
use PriceWatch\Service\Import\SharedGtinFlags;
use Propel\Runtime\Propel;

/**
 * Adds a product to the price watch from its own page, or pauses it.
 *
 * A variant new to the referential is read from the catalogue and goes
 * through the reference file's own checks: same normalisation, same
 * rejections, so an identity looks the same whichever way it came in. A
 * variant already in the referential only has its monitoring switched back
 * on: what an import brought (MPN, target price, notes) is never overwritten
 * by the thinner catalogue data.
 */
final readonly class ProductEnrolment
{
    /** The catalogue's own language: attribute labels exist in it. */
    public const LOCALE = 'fr_FR';

    public function __construct(
        private CatalogVariantRepository $catalog,
        private RowValidator $validator,
        private SaleElementResolver $resolver,
        private IdentityUpserter $upserter,
        private SharedGtinFlags $sharedGtinFlags,
    ) {
    }

    public function enrol(int $productId): EnrolmentReport
    {
        $report = new EnrolmentReport();
        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();

        try {
            $identities = $this->catalog->identitiesOf($productId);

            foreach ($this->catalog->referenceLines($productId, self::LOCALE) as $index => $columns) {
                $identity = $identities[(int) $columns['pse_id']] ?? null;

                if ($identity !== null) {
                    if ((int) $identity->getMonitored() === 1) {
                        ++$report->alreadyMonitored;
                    } else {
                        $identity->setMonitored(1)->save();
                        ++$report->resumed;
                    }

                    continue;
                }

                $variant = $columns['pse_ref'] !== '' ? $columns['pse_ref'] : '#'.$columns['pse_id'];
                $validated = $this->validator->validate($index + 1, $columns, implode(';', $columns));

                if ($validated instanceof RowRejection) {
                    $report->refused[] = ['variant' => $variant, 'reason' => $validated->message()];
                    continue;
                }

                $resolution = $this->resolver->resolve($validated);

                if (!$resolution->isAttached()) {
                    $report->refused[] = ['variant' => $variant, 'reason' => ($resolution->failure ?? ResolutionCode::AmbiguousPse)->message()];
                    continue;
                }

                $this->upserter->upsert($validated, $resolution, null, IdentityUpserter::SOURCE_CATALOG);
                ++$report->added;
            }

            if ($report->added > 0) {
                $this->sharedGtinFlags->refresh();
            }

            $connection->commit();
        } catch (\Throwable $error) {
            $connection->rollBack();

            throw $error;
        }

        return $report;
    }

    /**
     * Pauses or resumes monitoring of a product, or of one of its variants.
     * Nothing is deleted: the price history keeps its link to the variant.
     *
     * @return int variants whose monitoring changed
     */
    public function setMonitored(int $productId, bool $monitored, ?int $saleElementId = null): int
    {
        $query = PwProductIdentityQuery::create()
            ->filterByProductId($productId)
            ->filterByMonitored($monitored ? 0 : 1);

        if ($saleElementId !== null) {
            $query->filterByProductSaleElementsId($saleElementId);
        }

        return $query->update(['Monitored' => $monitored ? 1 : 0]);
    }
}
