<?php

declare(strict_types=1);

namespace PriceWatch\Command;

use PriceWatch\Service\Schedule\ScheduledProduct;
use PriceWatch\Service\Schedule\ScheduledRun;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Called every hour by the cron line. Nothing to do is not an error: it
 * exits 0, so cron mails only real failures.
 */
#[AsCommand(
    name: 'pricewatch:scheduled',
    description: 'Starts a price watch pass on the products due by their tier (settings page), in the standard queue. Does nothing when scheduling is off, outside its hours or while a pass runs.',
)]
final class ScheduledCommand extends Command
{
    private const MESSAGES = [
        'disabled' => 'Scheduled passes are off (settings page). Nothing done.',
        'outside_hours' => 'Outside the hours of the scheduled passes. Nothing done.',
        'no_competitor' => 'No competitor is whitelisted. Nothing done.',
        'running' => 'A pass is running. Nothing done.',
        'nothing_due' => 'No product is due. Nothing done.',
    ];

    public function __construct(
        private readonly ScheduledRun $scheduledRun,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the products the pass would take, start nothing')
            ->addOption('ignore-hours', null, InputOption::VALUE_NONE, 'Run outside the hours set on the settings page');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $outcome = $this->scheduledRun->tick(new \DateTimeImmutable(), (bool) $input->getOption('ignore-hours'), (bool) $input->getOption('dry-run'));

        if (isset(self::MESSAGES[$outcome['status']])) {
            $io->text(self::MESSAGES[$outcome['status']]);

            return self::SUCCESS;
        }

        $io->table(
            ['Product', 'Ref', 'Tier', 'Variants to search', 'Last read'],
            array_map(static fn (ScheduledProduct $product): array => [
                $product->productId,
                $product->ref,
                $product->tier->value,
                $product->collectable,
                $product->lastRead?->format('Y-m-d H:i') ?? 'never',
            ], $outcome['products']),
        );
        $io->text(\sprintf(
            '%s: %d product(s), %d variant(s) to search, at most %.3f USD (standard queue).',
            $outcome['status'] === 'dry_run' ? 'Would start' : 'Started',
            \count($outcome['products']),
            $outcome['variants'],
            $outcome['costUsd'],
        ));

        return self::SUCCESS;
    }
}
