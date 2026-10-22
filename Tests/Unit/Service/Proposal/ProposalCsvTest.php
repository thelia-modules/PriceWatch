<?php

declare(strict_types=1);

namespace PriceWatch\Tests\Unit\Service\Proposal;

use PHPUnit\Framework\TestCase;
use PriceWatch\Service\Proposal\ProposalCsv;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ProposalCsvTest extends TestCase
{
    public function testOneRowPerLineWithSemicolonsAndDecimalCommas(): void
    {
        $csv = (new ProposalCsv($this->translator()))->write([[
            'ref' => 'NEXX-X',
            'title' => 'Casque "X.WED3"',
            'sizes' => ['S', 'M'],
            'kind' => 'lower_declining',
            'ourPrice' => 479.0,
            'promo' => true,
            'purchase' => 250.5,
            'marginNowPct' => 37.24,
            'proposedPrice' => 449.0,
            'proposedExclTax' => 374.17,
            'changePct' => -6.26,
            'marginProposedPct' => 33.05,
            'floorPrice' => 375.79,
            'competitor' => 'dafy-moto.com',
            'competitorPrice' => 449.99,
            'gapPct' => -6.06,
            'seenAt' => '14/10/2026',
            'units' => 2,
            'unitsYearBefore' => 10,
            'unitsPeriodBefore' => 0,
        ]]);

        $rows = explode("\n", trim($csv));

        self::assertStringStartsWith("\u{feff}", $csv, 'accents survive in a spreadsheet');
        self::assertCount(2, $rows);
        self::assertSame(20, \count(str_getcsv($rows[0], ';', '"', '')));
        self::assertSame(
            ['NEXX-X', 'Casque "X.WED3"', 'S, M', '[proposals.kind.lower_declining]', '479,00', '[Yes]', '250,50', '37,2', '449,00', '374,17', '-6,3', '33,1', '375,79', 'dafy-moto.com', '449,99', '-6,1', '14/10/2026', '2', '10', '0'],
            str_getcsv($rows[1], ';', '"', ''),
        );
    }

    public function testAnUnknownValueIsAnEmptyCell(): void
    {
        $csv = (new ProposalCsv($this->translator()))->write([[
            'ref' => 'R', 'title' => 'T', 'sizes' => ['M'], 'kind' => 'lower', 'ourPrice' => 10.0, 'promo' => false,
            'purchase' => null, 'marginNowPct' => null, 'proposedPrice' => 9.0, 'proposedExclTax' => 7.5, 'changePct' => -10.0,
            'marginProposedPct' => null, 'floorPrice' => null, 'competitor' => null, 'competitorPrice' => null, 'gapPct' => null,
            'seenAt' => null, 'units' => 0, 'unitsYearBefore' => 0, 'unitsPeriodBefore' => 0,
        ]]);

        self::assertSame(['', '', '', ''], \array_slice(str_getcsv(explode("\n", trim($csv))[1], ';', '"', ''), 12, 4));
    }

    private function translator(): TranslatorInterface
    {
        return new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
            {
                return '['.$id.']';
            }

            public function getLocale(): string
            {
                return 'fr_FR';
            }
        };
    }
}
