<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use PriceWatch\Model\PwCollectRun;

/**
 * The steps of a pass started from the back office: a collect pass, then a
 * judge pass. Each step reports how far it is on the pass's own row, which
 * the back office reads to draw a progress bar.
 */
enum PassStep: string
{
    case TitleSearch = 'title_search';
    case GtinSearch = 'gtin_search';
    case Sellers = 'sellers';
    case Recording = 'recording';
    case Judging = 'judging';

    /** 1-based position among all the steps of a collect-then-judge run. */
    public function position(): int
    {
        return array_search($this, self::cases(), true) + 1;
    }

    /**
     * How far a collect-then-judge run is, over all its steps: the steps
     * before this one are done, this one is done/total of the way.
     */
    public function percent(int $done, int $total): int
    {
        $share = $total > 0 ? max(0, min($done, $total)) / $total : 0.0;

        return (int) floor(($this->position() - 1 + $share) / \count(self::cases()) * 100);
    }

    /**
     * Saves only when something changed: the wait loops report every few
     * seconds, most of the time with the same numbers.
     */
    public function record(PwCollectRun $run, int $done, int $total): void
    {
        $run->setProgressStep($this->value)
            ->setProgressDone(max(0, min($done, $total)))
            ->setProgressTotal(max(0, $total));

        if ($run->isModified()) {
            $run->save();
        }
    }
}
