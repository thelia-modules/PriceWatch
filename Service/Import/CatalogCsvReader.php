<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

/**
 * Reads the reference file: UTF-8, semicolon separated, one header line,
 * column order free, BOM tolerated.
 *
 * A missing product_ref column is a file-level failure, not a row rejection:
 * without it no line can designate anything, and reporting 4000 identical
 * rejections would bury the actual problem.
 */
final readonly class CatalogCsvReader
{
    public const DELIMITER = ';';
    public const ENCLOSURE = '"';

    private const REQUIRED_HEADER = 'product_ref';

    /**
     * Deliberately NOT a generator: opening the file and validating the header
     * must happen when read() is called, not when iteration starts. A
     * generator would defer both, and a caller that records an import batch
     * before iterating would leave that batch behind on an unreadable file.
     *
     * @return iterable<int, array{0: array<string, string>, 1: string}> line number, column map, raw line
     *
     * @throws MalformedReferenceFileException
     */
    public function read(string $path): iterable
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new MalformedReferenceFileException(sprintf('Reference file "%s" cannot be read.', $path));
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new MalformedReferenceFileException(sprintf('Reference file "%s" cannot be opened.', $path));
        }

        try {
            $header = $this->readHeader($handle, $path);
        } catch (MalformedReferenceFileException $error) {
            fclose($handle);

            throw $error;
        }

        return $this->rows($handle, $header);
    }

    /**
     * @param resource     $handle
     * @param list<string> $header
     *
     * @return \Generator<int, array{0: array<string, string>, 1: string}>
     */
    private function rows($handle, array $header): \Generator
    {
        try {
            $lineNumber = 1;

            while (($cells = fgetcsv($handle, 0, self::DELIMITER, self::ENCLOSURE)) !== false) {
                ++$lineNumber;

                if ($cells === [null]) {
                    continue; // a blank line, not even an empty row
                }

                yield $lineNumber => [$this->mapColumns($header, $cells), $this->rebuildRawLine($cells)];
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param resource $handle
     *
     * @return list<string>
     *
     * @throws MalformedReferenceFileException
     */
    private function readHeader($handle, string $path): array
    {
        $cells = fgetcsv($handle, 0, self::DELIMITER, self::ENCLOSURE);

        if ($cells === false || $cells === [null]) {
            throw new MalformedReferenceFileException(sprintf('Reference file "%s" has no header line.', $path));
        }

        $header = [];

        foreach ($cells as $index => $cell) {
            $name = strtolower(trim((string) $cell));

            if ($index === 0) {
                $name = preg_replace('/^\xEF\xBB\xBF/', '', $name) ?? $name;
            }

            $header[] = $name;
        }

        if (!\in_array(self::REQUIRED_HEADER, $header, true)) {
            throw new MalformedReferenceFileException(sprintf(
                'Reference file "%s" has no "%s" column. Columns found: %s.',
                $path,
                self::REQUIRED_HEADER,
                implode(', ', array_filter($header)) ?: 'none',
            ));
        }

        return $header;
    }

    /**
     * @param list<string>       $header
     * @param list<string|null>  $cells
     *
     * @return array<string, string>
     */
    private function mapColumns(array $header, array $cells): array
    {
        $row = [];

        foreach ($header as $index => $name) {
            if ($name === '') {
                continue;
            }

            $row[$name] = trim((string) ($cells[$index] ?? ''));
        }

        return $row;
    }

    /**
     * @param list<string|null> $cells
     */
    private function rebuildRawLine(array $cells): string
    {
        return implode(self::DELIMITER, array_map(static fn ($cell): string => (string) $cell, $cells));
    }
}
