<?php

declare(strict_types=1);

namespace PriceWatch\Command;

use PriceWatch\Service\Matching\OfferJudge;
use PriceWatch\Service\Run\AbandonedRuns;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'pricewatch:judge',
    description: 'Checks candidate offers of whitelisted competitors: same product, same variant, comparable price.',
)]
final class JudgeOffersCommand extends Command
{
    /** EX_TEMPFAIL: another pass is running. Not an error worth a cron alert. */
    public const LOCKED = 75;

    public function __construct(
        private readonly OfferJudge $judge,
        private readonly LockFactory $lockFactory,
        private readonly AbandonedRuns $abandonedRuns,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of offers judged in this pass', '200')
            ->addOption('budget-usd', null, InputOption::VALUE_REQUIRED, 'Stop before spending more than this on Jev', '0.30')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Count what would be judged, call nothing, write nothing on offers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $lock = $this->lockFactory->createLock('pricewatch.judge');

        if (!$lock->acquire(false)) {
            $io->warning('A judge pass is already running.');

            return self::LOCKED;
        }

        // Holding the lock, any row of this pass still "started" is a dead one.
        if (($abandoned = $this->abandonedRuns->close(OfferJudge::PASS)) > 0) {
            $io->note(\sprintf('%d interrupted pass(es) closed as failed.', $abandoned));
        }

        try {
            $run = $this->judge->judgePending(
                max(1, (int) $input->getOption('limit')),
                max(0.0, (float) $input->getOption('budget-usd')),
                (bool) $input->getOption('dry-run'),
            );
        } finally {
            $lock->release();
        }

        $io->table(['', ''], [
            ['Offers seen', $run->getOffersSeen()],
            ['Matched', $run->getOffersMatched()],
            ['Sent to review', $run->getOffersReview()],
            ['Rejected', $run->getOffersRejected()],
            ['Jev requests', $run->getJevRequests()],
            ['Answers from cache', $run->getJevCacheHits()],
            ['Jev cost (USD)', $run->getJevCostUsd()],
        ]);

        $errors = json_decode((string) $run->getErrorsJson(), true);

        if (\is_array($errors) && $errors !== []) {
            $io->warning($errors);
        }

        return self::SUCCESS;
    }
}
