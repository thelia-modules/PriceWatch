<?php

declare(strict_types=1);

namespace PriceWatch\Command;

use PriceWatch\Service\Direct\DirectCollector;
use PriceWatch\Service\Run\AbandonedRuns;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'pricewatch:direct',
    description: 'Reads competitor prices straight from the product pages tied to our products (pricewatch:page). Free; exact matches only.',
)]
final class DirectCollectCommand extends Command
{
    public const LOCKED = 75;

    public function __construct(
        private readonly DirectCollector $collector,
        private readonly LockFactory $lockFactory,
        private readonly AbandonedRuns $abandonedRuns,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('product', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only the pages of these product refs (repeatable)')
            ->addOption('competitor', null, InputOption::VALUE_REQUIRED, 'Only the pages of this competitor domain');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('pricewatch.direct');

        if (!$lock->acquire(false)) {
            $io->warning('A direct pass is already running.');

            return self::LOCKED;
        }

        if (($abandoned = $this->abandonedRuns->close(DirectCollector::PASS)) > 0) {
            $io->note(\sprintf('%d interrupted pass(es) closed as failed.', $abandoned));
        }

        try {
            $result = $this->collector->collect(
                array_values(array_filter((array) $input->getOption('product'), 'is_string')),
                \is_string($input->getOption('competitor')) ? $input->getOption('competitor') : null,
            );
        } finally {
            $lock->release();
        }

        $run = $result['run'];
        $io->table(['', ''], [
            ['Pages fetched', $run->getPagesFetched()],
            ['HTTP errors', $run->getHttpErrors()],
            ['Variants read on the pages', $run->getOffersSeen()],
            ['Our variants matched', $run->getOffersMatched()],
            ['Readings recorded', $run->getQuotesWritten()],
        ]);
        $io->table(
            ['Page', 'Status', 'Outcome', 'Recorded', 'URL'],
            array_map(static fn (array $page): array => [$page['id'], $page['status'], trim($page['outcome'].' '.($page['detail'] ?? '')), $page['recorded'], $page['url']], $result['pages']),
        );

        return self::SUCCESS;
    }
}
