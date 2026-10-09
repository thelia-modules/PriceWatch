<?php

declare(strict_types=1);

namespace PriceWatch\Service\Dev;

/**
 * Reads the shop's catalogue export (ref, gtin, product title, axis title,
 * axis value, price excluding tax), one line per variant value.
 *
 * A variant spread over several axes takes several lines with the same
 * ref + gtin; they are merged. A line without GTIN is keyed on its axis
 * values instead, so two GTIN-less sizes do not collapse into one.
 */
final readonly class CatalogExportReader
{
    private const EMPTY_MARKERS = ['NULL', 'NONE', 'N/A', '-'];

    /**
     * @return array{products: array<string, array{title: string, variants: list<array{gtin: string, price: string, axes: array<string, string>}>}>, lines: int, axisConflicts: int}
     */
    public function read(string $path): array
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Cannot read "%s".', $path));
        }

        $header = fgetcsv($handle, 0, ',', '"', '');

        if ($header === false || \count($header) < 6) {
            throw new \RuntimeException('Expected 6 columns: ref, gtin, title, axis title, axis value, price.');
        }

        $products = [];
        $variantIndex = [];
        $lines = 0;
        $axisConflicts = 0;

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            if ($cells === [null] || \count($cells) < 6) {
                continue;
            }

            ++$lines;
            [$ref, $gtin, $title, $axis, $value, $price] = array_map(static fn ($cell): string => trim((string) $cell), \array_slice($cells, 0, 6));

            if ($ref === '') {
                continue;
            }

            // The export writes a missing GTIN as the literal text NULL. Taken
            // at face value it becomes a shared key and folds every size of the
            // product into a single variant.
            if (\in_array(strtoupper($gtin), self::EMPTY_MARKERS, true)) {
                $gtin = '';
            }

            $products[$ref] ??= ['title' => $title, 'variants' => []];
            $key = $gtin !== '' ? 'gtin:'.$gtin : 'axes:'.$axis.'='.$value;

            if (!isset($variantIndex[$ref][$key])) {
                $variantIndex[$ref][$key] = \count($products[$ref]['variants']);
                $products[$ref]['variants'][] = ['gtin' => $gtin, 'price' => $price, 'axes' => []];
            }

            $variant = &$products[$ref]['variants'][$variantIndex[$ref][$key]];

            if ($axis !== '' && $value !== '') {
                if (isset($variant['axes'][$axis]) && $variant['axes'][$axis] !== $value) {
                    // Same variant, same axis, two values: the export is
                    // inconsistent. First value kept, the conflict counted.
                    ++$axisConflicts;
                } else {
                    $variant['axes'][$axis] = $value;
                }
            }

            unset($variant);
        }

        fclose($handle);

        return ['products' => $products, 'lines' => $lines, 'axisConflicts' => $axisConflicts];
    }
}
