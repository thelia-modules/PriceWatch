<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

use PriceWatch\Model\PwImportBatch;
use PriceWatch\Model\PwImportReject;
use PriceWatch\Model\PwProductIdentityQuery;
use Propel\Runtime\ActiveQuery\Criteria;
use Propel\Runtime\Propel;

/**
 * Runs one import of the reference file.
 *
 * A refused line never stops the import and is never lost: it lands in
 * pw_import_reject with its line number, its code and its raw text, and the
 * batch ends as partial. Only a file-level problem - unreadable, no header, no
 * product_ref column - aborts the whole thing.
 *
 * The module creates nothing in the catalogue. An unknown product_ref is a
 * rejection, not an invitation to create a product.
 */
final readonly class ReferenceImporter
{
    private const COMMIT_EVERY = 200;

    public function __construct(
        private CatalogCsvReader $reader,
        private RowValidator $validator,
        private SaleElementResolver $resolver,
        private IdentityUpserter $upserter,
        private CompetitorSeeder $competitorSeeder,
        private SharedGtinFlags $sharedGtinFlags,
    ) {
    }

    /**
     * @throws MalformedReferenceFileException
     */
    public function import(string $path, ImportOptions $options): ImportReport
    {
        // Opened before the batch row so an unreadable file does not leave a
        // started batch behind.
        $rows = $this->reader->read($path);

        $batch = $this->openBatch($path, $options);
        $report = new ImportReport((int) $batch->getId(), $options->dryRun);

        $claimedSaleElements = [];
        $seenSaleElements = [];
        $connection = Propel::getConnection('TheliaMain');
        $connection->beginTransaction();
        $sinceCommit = 0;

        try {
            foreach ($rows as $lineNumber => [$columns, $rawLine]) {
                ++$report->linesTotal;

                $this->handleLine(
                    $lineNumber,
                    $columns,
                    $rawLine,
                    $options,
                    $report,
                    $batch,
                    $claimedSaleElements,
                    $seenSaleElements,
                );

                if (++$sinceCommit >= self::COMMIT_EVERY) {
                    $connection->commit();
                    $connection->beginTransaction();
                    $sinceCommit = 0;
                }
            }

            if (!$options->dryRun) {
                if ($options->deactivateMissing) {
                    $report->deactivated = $this->deactivateMissing($seenSaleElements);
                }

                $this->sharedGtinFlags->refresh();
            }

            $this->closeBatch($batch, $report);
            $connection->commit();
        } catch (\Throwable $error) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }

            $batch->setStatus('failed')->setFinishedAt(new \DateTime())->save();

            throw $error;
        }

        return $report;
    }

    /**
     * @param array<string, string> $columns
     * @param array<int, int>       $claimedSaleElements
     * @param list<int>             $seenSaleElements
     */
    private function handleLine(
        int $lineNumber,
        array $columns,
        string $rawLine,
        ImportOptions $options,
        ImportReport $report,
        PwImportBatch $batch,
        array &$claimedSaleElements,
        array &$seenSaleElements,
    ): void {
        if ($options->locale !== null && trim($columns['locale'] ?? '') === '') {
            $columns['locale'] = $options->locale;
        }

        $validated = $this->validator->validate($lineNumber, $columns, $rawLine);

        if ($validated instanceof RowRejection) {
            $this->recordRejection($batch, $report, $lineNumber, $validated->code->value, $validated->message(), $rawLine);

            return;
        }

        $resolution = $this->resolver->resolve($validated);

        if (!$resolution->isAttached()) {
            $code = $resolution->failure ?? ResolutionCode::AmbiguousPse;
            $this->recordRejection($batch, $report, $lineNumber, $code->value, $code->message(), $rawLine);

            return;
        }

        $saleElementId = (int) $resolution->saleElementId;

        if (isset($claimedSaleElements[$saleElementId])) {
            $code = ResolutionCode::DuplicateInFile;
            $this->recordRejection(
                $batch,
                $report,
                $lineNumber,
                $code->value,
                $code->message().' Line '.$claimedSaleElements[$saleElementId].' claimed it first.',
                $rawLine,
            );

            return;
        }

        $claimedSaleElements[$saleElementId] = $lineNumber;
        $seenSaleElements[] = $saleElementId;

        if ($options->dryRun) {
            // The outcome is not predicted: a dry run reports what it could
            // read and attach, never a made-up created/updated split.
            return;
        }

        $outcome = $this->upserter->upsert($validated, $resolution, (int) $batch->getId());

        match ($outcome) {
            IdentityUpserter::CREATED => ++$report->created,
            IdentityUpserter::UPDATED => ++$report->updated,
            IdentityUpserter::UNCHANGED => ++$report->unchanged,
            default => throw new \LogicException('Unknown upsert outcome '.$outcome),
        };

        $report->competitorsSeeded += $this->competitorSeeder->seed($validated->competitorDomains);
    }

    private function recordRejection(
        PwImportBatch $batch,
        ImportReport $report,
        int $lineNumber,
        string $code,
        string $message,
        string $rawLine,
    ): void {
        $report->reject($lineNumber, $code, $message);

        (new PwImportReject())
            ->setBatchId($batch->getId())
            ->setLineNumber($lineNumber)
            ->setErrorCode($code)
            ->setMessage(mb_substr($message, 0, 255))
            ->setRawLine($rawLine)
            ->save();
    }

    private function openBatch(string $path, ImportOptions $options): PwImportBatch
    {
        $batch = new PwImportBatch();
        $batch
            ->setFilename(mb_substr(basename($path), 0, 255))
            ->setSourceChecksum(sha1_file($path) ?: null)
            ->setLocale($options->locale ?? 'fr_FR')
            ->setDryRun($options->dryRun ? 1 : 0)
            ->setStatus('started')
            ->setStartedAt(new \DateTime())
            ->save();

        return $batch;
    }

    private function closeBatch(PwImportBatch $batch, ImportReport $report): void
    {
        $batch
            ->setStatus($report->status())
            ->setLinesTotal($report->linesTotal)
            ->setLinesCreated($report->created)
            ->setLinesUpdated($report->updated)
            ->setLinesUnchanged($report->unchanged)
            ->setLinesRejected($report->rejected)
            ->setCompetitorsSeeded($report->competitorsSeeded)
            ->setFinishedAt(new \DateTime())
            ->save();
    }

    /**
     * @param list<int> $seenSaleElements
     */
    private function deactivateMissing(array $seenSaleElements): int
    {
        // NOT IN on an empty array matches every row in Propel, which would
        // unmonitor the whole referential. An empty file deactivates nothing.
        if ($seenSaleElements === []) {
            return 0;
        }

        return PwProductIdentityQuery::create()
            ->filterByMonitored(1)
            ->filterByProductSaleElementsId(array_values(array_unique($seenSaleElements)), Criteria::NOT_IN)
            ->update(['Monitored' => 0]);
    }
}
