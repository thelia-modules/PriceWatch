<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Command\CollectOffersCommand;
use PriceWatch\Command\DirectCollectCommand;
use PriceWatch\Command\JudgeOffersCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Only the three commands a pass is made of can be run: a step is a name from
 * this list, never something read from the message.
 */
final readonly class CommandStepRunner implements StepRunner
{
    public function __construct(
        private DirectCollectCommand $direct,
        private CollectOffersCommand $collect,
        private JudgeOffersCommand $judge,
    ) {
    }

    public function run(string $command, array $input, OutputInterface $output): int
    {
        $step = match ($command) {
            'pricewatch:direct' => $this->direct,
            'pricewatch:collect' => $this->collect,
            'pricewatch:judge' => $this->judge,
            default => throw new \InvalidArgumentException(\sprintf('"%s" is not a step of a price watch pass.', $command)),
        };

        return $step->run(new ArrayInput($input), $output);
    }
}
