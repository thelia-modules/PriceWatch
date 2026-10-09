<?php

declare(strict_types=1);

namespace PriceWatch\Command\Dev;

use PriceWatch\Service\Jev\JevClient;
use PriceWatch\Service\Jev\JevException;
use PriceWatch\Service\Jev\OfferQuestions;
use PriceWatch\Service\Jev\OfferState;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Development only: asks the offer questions once against the live Jev
 * service, on an offer typed by hand, to check wiring and wording before any
 * collection exists.
 */
#[AsCommand(
    name: 'pricewatch:dev:probe-jev',
    description: 'DEVELOPMENT ONLY. Asks the three offer questions about one hand-written competitor offer (a fraction of a cent).',
)]
final class ProbeJevCommand extends Command
{
    public function __construct(
        private readonly JevClient $jev,
        private readonly OfferQuestions $questions,
        private readonly OfferState $offerState,
        private readonly string $kernelEnvironment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('ours-title', null, InputOption::VALUE_REQUIRED)
            ->addOption('ours-brand', null, InputOption::VALUE_REQUIRED)
            ->addOption('ours-size', null, InputOption::VALUE_REQUIRED)
            ->addOption('ours-price', null, InputOption::VALUE_REQUIRED)
            ->addOption('their-title', null, InputOption::VALUE_REQUIRED)
            ->addOption('their-variant', null, InputOption::VALUE_REQUIRED)
            ->addOption('their-price', null, InputOption::VALUE_REQUIRED)
            ->addOption('their-list-price', null, InputOption::VALUE_REQUIRED)
            ->addOption('their-condition', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->kernelEnvironment === 'prod') {
            $io->error('Development only.');

            return self::FAILURE;
        }

        $option = static fn (string $name): ?string => \is_string($input->getOption($name)) && $input->getOption($name) !== '' ? $input->getOption($name) : null;

        $state = $this->offerState->build(
            ['title' => (string) $option('ours-title'), 'brand' => $option('ours-brand'), 'size' => $option('ours-size'), 'variant' => $option('ours-size'), 'gtin' => null, 'price_incl_tax' => $option('ours-price')],
            ['title' => (string) $option('their-title'), 'seller' => 'probe', 'variant' => $option('their-variant'), 'description' => null, 'price' => $option('their-price'), 'list_price' => $option('their-list-price'), 'shipping' => null, 'currency' => 'EUR', 'availability' => 'in_stock', 'condition' => $option('their-condition'), 'annotation' => null],
        );

        try {
            $result = $this->jev->ask($state, $this->questions->all());
        } catch (JevException $error) {
            $io->error($error->getMessage());

            return self::FAILURE;
        }

        $rows = [];

        foreach ($result['answers'] as $id => $answer) {
            $rows[] = [$id, \is_float($answer->value) ? number_format($answer->value, 3) : $answer->value, $answer->confidence === null ? '-' : number_format($answer->confidence, 2), $answer->band];
        }

        $io->table(['Question', 'Answer', 'Confidence', 'Band'], $rows);
        $io->writeln(\sprintf('Model %s, %d input tokens, %.6f USD', (string) $result['model'], $result['inputTokens'], $result['costUsd']));

        return self::SUCCESS;
    }
}
