<?php

declare(strict_types=1);

namespace PriceWatch\Command;

use PriceWatch\Service\Import\ImportOptions;
use PriceWatch\Service\Import\MalformedReferenceFileException;
use PriceWatch\Service\Import\ReferenceImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * extends Command with constructor injection rather than
 * ContainerAwareCommand: getContainer() and initRequest() invite exactly the
 * request, session and service-locator access that breaks in CLI.
 */
#[AsCommand(
    name: 'pricewatch:catalog:import',
    description: 'Imports the monitored product reference file (CSV, semicolon separated).',
)]
final class ImportCatalogCommand extends Command
{
    private const REJECTIONS_SHOWN = 20;

    public function __construct(private readonly ReferenceImporter $importer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the CSV reference file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Read, validate and attach without writing any identity')
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale used when a line leaves the locale column empty')
            ->addOption(
                'deactivate-missing',
                null,
                InputOption::VALUE_NONE,
                'Set monitored = 0 on every monitored variant absent from this file, across the whole referential and not just this file\'s products. Only use it when the file is the complete source of truth. Never deletes, so price history keeps its attachment.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $file = (string) $input->getArgument('file');
        $dryRun = (bool) $input->getOption('dry-run');

        $locale = $input->getOption('locale');
        $options = new ImportOptions(
            dryRun: $dryRun,
            locale: \is_string($locale) && $locale !== '' ? $locale : null,
            deactivateMissing: (bool) $input->getOption('deactivate-missing'),
        );

        try {
            $report = $this->importer->import($file, $options);
        } catch (MalformedReferenceFileException $error) {
            $io->error($error->getMessage());

            return self::INVALID;
        }

        $io->title($dryRun ? 'Reference import, dry run' : 'Reference import');

        $rows = [
            ['Lines read', $report->linesTotal],
            ['Attached', $report->linesTotal - $report->rejected],
            ['Refused', $report->rejected],
        ];

        if ($dryRun) {
            $io->table(['', ''], $rows);
            $io->note('Dry run: nothing was written apart from the batch and its refused lines. Created, updated and unchanged counts are deliberately not predicted.');
        } else {
            $rows[] = ['Created', $report->created];
            $rows[] = ['Updated', $report->updated];
            $rows[] = ['Unchanged', $report->unchanged];
            $rows[] = ['Competitors proposed', $report->competitorsSeeded];

            if ($options->deactivateMissing) {
                $rows[] = ['No longer monitored', $report->deactivated];
            }

            $io->table(['', ''], $rows);
        }

        if ($report->rejections !== []) {
            $io->section(\sprintf('Refused lines (%d)', $report->rejected));
            $io->table(
                ['Line', 'Code', 'Reason'],
                array_map(
                    static fn (array $rejection): array => [$rejection['line'], $rejection['code'], $rejection['message']],
                    \array_slice($report->rejections, 0, self::REJECTIONS_SHOWN),
                ),
            );

            if ($report->rejected > self::REJECTIONS_SHOWN) {
                $io->comment(\sprintf(
                    'Showing the first %d. The full list is in pw_import_reject for batch %d.',
                    self::REJECTIONS_SHOWN,
                    (int) $report->batchId,
                ));
            }
        }

        // partial is reported in the batch status and on screen, not as a
        // failure exit code: the lines that were fine did go in.
        $report->rejected === 0
            ? $io->success(\sprintf('Batch %d: every line went through.', (int) $report->batchId))
            : $io->warning(\sprintf('Batch %d is partial: %d line(s) refused.', (int) $report->batchId, $report->rejected));

        return self::SUCCESS;
    }
}
