<?php

declare(strict_types=1);

namespace PriceWatch\Command;

use PriceWatch\Service\Import\ReferenceExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pricewatch:catalog:export',
    description: 'Exports the monitored referential in the same format the import reads.',
)]
final class ExportCatalogCommand extends Command
{
    public function __construct(private readonly ReferenceExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path of the CSV file to write')
            ->addOption('monitored-only', null, InputOption::VALUE_NONE, 'Export only variants still monitored');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $path = (string) $input->getArgument('file');

        if (is_file($path)) {
            $io->error(\sprintf('"%s" already exists. Pick another path rather than overwriting a file.', $path));

            return self::INVALID;
        }

        $written = $this->exporter->write($path, (bool) $input->getOption('monitored-only'));

        $io->success(\sprintf('%d line(s) written to %s.', $written, $path));
        $io->comment('Round trip check: pricewatch:catalog:import '.$path.' --dry-run should attach every line and refuse none.');

        return self::SUCCESS;
    }
}
