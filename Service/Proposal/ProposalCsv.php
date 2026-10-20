<?php

declare(strict_types=1);

namespace PriceWatch\Service\Proposal;

use PriceWatch\Service\Admin\AdminRequestGuard;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The proposals as a spreadsheet opens them in France: semicolons, decimal
 * commas, a byte order mark so that accents survive.
 */
final readonly class ProposalCsv
{
    private const COLUMNS = [
        'Reference', 'Product', 'Sizes', 'Proposal', 'Our price incl. tax', 'Promotion', 'Purchase price excl. tax',
        'Margin now %', 'Proposed price incl. tax', 'Proposed price excl. tax', 'Change %', 'Margin then %',
        'Floor price incl. tax', 'Cheapest competitor', 'Competitor price', 'Gap %', 'Read on',
        'Units 90 days', 'Units same 90 days a year before', 'Units 90 days before',
    ];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param list<array<string, mixed>> $lines PriceProposalPresenter::lines()
     */
    public function write(array $lines): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('No temporary stream for the CSV.');
        }

        fwrite($handle, "\u{feff}");
        fputcsv($handle, array_map(fn (string $column): string => $this->trans($column), self::COLUMNS), ';', '"', '');

        foreach ($lines as $line) {
            fputcsv($handle, [
                $line['ref'],
                $line['title'],
                implode(', ', $line['sizes']),
                $this->trans('proposals.kind.'.$line['kind']),
                self::number($line['ourPrice']),
                $line['promo'] ? $this->trans('Yes') : '',
                self::number($line['purchase']),
                self::number($line['marginNowPct'], 1),
                self::number($line['proposedPrice']),
                self::number($line['proposedExclTax']),
                self::number($line['changePct'], 1),
                self::number($line['marginProposedPct'], 1),
                self::number($line['floorPrice']),
                $line['competitor'] ?? '',
                self::number($line['competitorPrice']),
                self::number($line['gapPct'], 1),
                $line['seenAt'] ?? '',
                $line['units'],
                $line['unitsYearBefore'],
                $line['unitsPeriodBefore'],
            ], ';', '"', '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], AdminRequestGuard::MODULE_CODE);
    }

    private static function number(mixed $value, int $decimals = 2): string
    {
        return $value === null ? '' : number_format((float) $value, $decimals, ',', '');
    }
}
