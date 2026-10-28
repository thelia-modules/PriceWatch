<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Thelia\Messenger\Message\DescribedJob;

/**
 * One price watch pass asked for, as it travels on the queue: only plain
 * values, never a command line. What the worker runs is fixed by
 * {@see steps()}; whoever can write to the queue cannot make it run anything else.
 */
#[Exclude]
final readonly class RunPass implements DescribedJob
{
    /**
     * Seconds the collect pass waits on each batch of tasks: minutes in the
     * priority queue, up to the 45 minutes of the standard one.
     */
    private const PRIORITY_MAX_WAIT = 600;
    private const STANDARD_MAX_WAIT = 3000;

    /**
     * @param string       $passId      identifies this request: a message delivered twice runs once
     * @param list<string> $productRefs only these products' variants; empty: the ones collected longest ago
     */
    public function __construct(
        public string $passId,
        public int $variants,
        public array $productRefs = [],
        public bool $priority = true,
        public string $logFile = ManualRun::LOG_FILE,
    ) {
    }

    public function describe(): string
    {
        return \sprintf('Price watch pass %s on %d variant(s)', $this->passId, $this->variants);
    }

    /**
     * Free first: the sitemaps and the known pages, so Google is only asked
     * for the products no page covers; then the pages Google led to, before
     * the judge pass. The worst case announced on the button is the budget the
     * collect command enforces, so the click can never cost more than it said.
     *
     * @return list<array{command: string, input: array<string, mixed>}>
     */
    public function steps(): array
    {
        $variants = max(1, $this->variants);
        $products = $this->productRefs === [] ? [] : ['--product' => $this->productRefs];

        return [
            ['command' => 'pricewatch:direct', 'input' => ['--discover' => true] + $products],
            ['command' => 'pricewatch:collect', 'input' => [
                '--limit' => (string) $variants,
                '--max-wait' => (string) ($this->priority ? self::PRIORITY_MAX_WAIT : self::STANDARD_MAX_WAIT),
                '--budget-usd' => number_format(ManualRun::worstCaseUsd($variants, $this->priority), 3, '.', ''),
            ] + ($this->priority ? ['--priority' => true] : []) + $products],
            ['command' => 'pricewatch:direct', 'input' => ['--candidates' => true] + $products],
            ['command' => 'pricewatch:judge', 'input' => []],
        ];
    }
}
