<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

use Propel\Runtime\Propel;

/**
 * A GTIN carried by more than one variant can never designate one, so it is
 * flagged once whenever the referential changes rather than recomputed at
 * every lookup: after an import, and after a product is added by hand.
 */
final readonly class SharedGtinFlags
{
    public function refresh(): void
    {
        $connection = Propel::getConnection('TheliaMain');

        $connection->exec('UPDATE pw_product_identity SET gtin_shared = 0 WHERE gtin_shared <> 0');
        $connection->exec(
            'UPDATE pw_product_identity i
             JOIN (
                 SELECT gtin FROM pw_product_identity
                 WHERE gtin IS NOT NULL AND gtin <> \'\'
                 GROUP BY gtin HAVING COUNT(*) > 1
             ) shared ON shared.gtin = i.gtin
             SET i.gtin_shared = 1',
        );
    }
}
