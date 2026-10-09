<?php

declare(strict_types=1);

namespace PriceWatch\Command;

use PriceWatch\Service\Source\DataForSeoException;
use PriceWatch\Service\Source\ShoppingCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'pricewatch:collect',
    description: 'Collects competitor offers from Google Shopping for monitored variants with a usable GTIN. Offers land as candidates for pricewatch:judge.',
)]
final class CollectOffersCommand extends Command
{
    public const LOCKED = 75;

    /** DataForSEO list prices, 2026-10-08: per task, standard and priority queues. */
    private const COST_PER_TASK = [1 => 0.001, 2 => 0.002];

    public function __construct(
        private readonly ShoppingCollector $collector,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of variants searched', '10')
            ->addOption('product', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only these product refs (repeatable)')
            ->addOption('priority', null, InputOption::VALUE_NONE, 'Priority queue: results in about a minute, twice the price')
            ->addOption('max-wait', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for each batch of tasks', '600')
            ->addOption('budget-usd', null, InputOption::VALUE_REQUIRED, 'Refuse to start if the worst case exceeds this', '1.00')
            ->addOption('sandbox', null, InputOption::VALUE_NONE, 'Free DataForSEO sandbox: made-up data, same structure')
            ->addOption('one-per-product', null, InputOption::VALUE_NONE, 'One variant per product: samples the catalogue instead of all sizes of the first products');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int) $input->getOption('limit'));
        $priority = $input->getOption('priority') ? 2 : 1;
        $sandbox = (bool) $input->getOption('sandbox');

        // Three tasks per variant at most: a GTIN search, a title search
        // when the GTIN leads nowhere, one sellers lookup.
        $worstCase = $sandbox ? 0.0 : $limit * 3 * self::COST_PER_TASK[$priority];

        if ($worstCase > (float) $input->getOption('budget-usd')) {
            $io->error(\sprintf('Worst case %.3f USD exceeds the budget. Lower --limit or raise --budget-usd.', $worstCase));

            return self::INVALID;
        }

        $lock = $this->lockFactory->createLock('pricewatch.collect');

        if (!$lock->acquire(false)) {
            $io->warning('A collect pass is already running.');

            return self::LOCKED;
        }

        try {
            $result = $this->collector->collect(
                $limit,
                $priority,
                max(10, (int) $input->getOption('max-wait')),
                array_values(array_filter((array) $input->getOption('product'), 'is_string')),
                $sandbox,
                (bool) $input->getOption('one-per-product'),
            );
        } catch (DataForSeoException $error) {
            $io->error($error->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $run = $result['run'];
        $io->table(['', ''], [
            ['Variants searched', $run->getTargetsPlanned()],
            ['Searches without a plausible card', \count($result['noPlausibleCard'])],
            ['Offers seen', $run->getOffersSeen()],
            ['Whitelisted offers recorded', $run->getQuotesWritten()],
            ['DataForSEO tasks', $run->getDataforseoTasks()],
            ['DataForSEO cost (USD)', $run->getDataforseoCostUsd()],
            ['Worst case announced (USD)', number_format($worstCase, 3)],
        ]);

        if ($result['noPlausibleCard'] !== []) {
            $io->section('Searches without a plausible card (ours => first cards Google returned)');
            $io->listing(array_map(
                static fn (string $ours, array $cards): string => $ours.' => '.($cards === [] ? '(no card)' : implode(' | ', $cards)),
                array_keys($result['noPlausibleCard']),
                $result['noPlausibleCard'],
            ));
        }

        if ($result['unknownSellers'] !== []) {
            $io->section('Sellers seen outside the whitelist (candidates for the competitor list)');
            $io->listing(array_map(
                static fn (string $seller, int $count): string => $seller.' ('.$count.')',
                array_keys(\array_slice($result['unknownSellers'], 0, 15, true)),
                \array_slice($result['unknownSellers'], 0, 15, true),
            ));
        }

        $errors = json_decode((string) $run->getErrorsJson(), true);

        if (\is_array($errors) && $errors !== []) {
            $io->warning($errors);
        }

        $io->note('Next: pricewatch:judge checks that each recorded offer is the same product and variant.');

        return self::SUCCESS;
    }
}
