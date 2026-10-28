<?php

declare(strict_types=1);

namespace PriceWatch\Command;

use PriceWatch\Service\Competitor\CompetitorDiscovery;
use PriceWatch\Service\Competitor\DiscoveryException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pricewatch:competitors:suggest',
    description: 'Asks the model of the AI layer for competitor candidates (needs the function turned on in the settings). They land as proposals, never on the whitelist.',
)]
final class SuggestCompetitorsCommand extends Command
{
    public function __construct(private readonly CompetitorDiscovery $discovery)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('max', null, InputOption::VALUE_REQUIRED, 'Maximum number of proposals', (string) CompetitorDiscovery::DEFAULT_MAXIMUM)
            ->addOption('locale', null, InputOption::VALUE_REQUIRED, 'Locale used to read catalogue titles', 'fr_FR');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maximum = max(1, min(50, (int) $input->getOption('max')));

        try {
            $report = $this->discovery->suggest((string) $input->getOption('locale'), $maximum);
        } catch (DiscoveryException $error) {
            $io->error($error->getMessage());

            return self::FAILURE;
        }

        $io->table(['', ''], [
            ['New proposals', \count($report->proposed)],
            ['Already on a list', \count($report->alreadyKnown)],
            ['Blacklisted, ignored', \count($report->blacklisted)],
            ['No DNS record, dropped', \count($report->unresolved)],
            ['Unusable or own domain', \count($report->invalid)],
            ['Model', (string) $report->model],
        ]);

        if ($report->proposed !== []) {
            $io->listing($report->proposed);
        }

        $io->note('Proposals wait for a decision in the back-office: Catalogue > Price watch > Competitors.');

        return self::SUCCESS;
    }
}
