<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

final class ImportReport
{
    public int $linesTotal = 0;
    public int $created = 0;
    public int $updated = 0;
    public int $unchanged = 0;
    public int $rejected = 0;
    public int $competitorsSeeded = 0;
    public int $deactivated = 0;

    /** @var list<array{line: int, code: string, message: string}> */
    public array $rejections = [];

    public function __construct(
        public readonly ?int $batchId,
        public readonly bool $dryRun,
    ) {
    }

    public function reject(int $lineNumber, string $code, string $message): void
    {
        ++$this->rejected;
        $this->rejections[] = ['line' => $lineNumber, 'code' => $code, 'message' => $message];
    }

    /**
     * partial as soon as one line was refused: a caller must never read a
     * success as "the whole file is in".
     */
    public function status(): string
    {
        return $this->rejected === 0 ? 'success' : 'partial';
    }
}
