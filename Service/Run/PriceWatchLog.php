<?php

declare(strict_types=1);

namespace PriceWatch\Service\Run;

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The price watch's own log, var/log/pricewatch.log: one readable line per
 * step of a pass (start, tasks posted, wait, errors, end), separate from the
 * shop's logs so it can be read without digging.
 *
 * Messages carry no credentials: DataForSEO errors are built from status
 * codes and texts only, and keys never enter a context.
 */
final class PriceWatchLog extends AbstractLogger
{
    public const FILE = 'pricewatch.log';

    private ?Logger $logger = null;

    public function __construct(
        #[Autowire('%kernel.logs_dir%')]
        private readonly string $logsDir,
    ) {
    }

    public function path(): string
    {
        return $this->logsDir.'/'.self::FILE;
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logger()->log($level, $message, $context);
    }

    private function logger(): Logger
    {
        if ($this->logger === null) {
            $handler = new StreamHandler($this->path());
            // Placeholders such as {run} are replaced in the line itself; what
            // is left in the context (an exception, a task id) follows it.
            $handler->setFormatter(new LineFormatter("[%datetime%] %level_name%: %message% %context%\n", 'Y-m-d H:i:s', true, true));
            $handler->pushProcessor(new \Monolog\Processor\PsrLogMessageProcessor(null, true));
            $this->logger = new Logger('pricewatch', [$handler]);
        }

        return $this->logger;
    }
}
