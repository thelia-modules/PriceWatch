<?php

declare(strict_types=1);

namespace PriceWatch\Service\Import;

final readonly class RowRejection
{
    public function __construct(
        public int $lineNumber,
        public RejectCode $code,
        public string $rawLine,
        public ?string $detail = null,
    ) {
    }

    public function message(): string
    {
        return $this->detail === null
            ? $this->code->message()
            : $this->code->message().' ('.$this->detail.')';
    }
}
