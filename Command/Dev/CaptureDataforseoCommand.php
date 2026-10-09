<?php

declare(strict_types=1);

namespace PriceWatch\Command\Dev;

use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretName;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Development only: records raw DataForSEO answers as test fixtures, so the
 * response mapper is written against what the API actually sends rather than
 * against a reading of its documentation.
 *
 * Sandbox by default: free, same structure, made-up data. Credentials come
 * from the encrypted settings and never leave this process.
 */
#[AsCommand(
    name: 'pricewatch:dev:capture-dataforseo',
    description: 'DEVELOPMENT ONLY. Saves raw DataForSEO Merchant answers (products then sellers) as JSON fixtures.',
)]
final class CaptureDataforseoCommand extends Command
{
    private const SANDBOX = 'https://sandbox.dataforseo.com/v3';
    private const LIVE = 'https://api.dataforseo.com/v3';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly PriceWatchSettings $settings,
        private readonly string $kernelEnvironment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('keyword', InputArgument::REQUIRED, 'Search keyword, typically a GTIN')
            ->addArgument('directory', InputArgument::REQUIRED, 'Where to write the JSON files')
            ->addOption('live', null, InputOption::VALUE_NONE, 'Use the paying API instead of the sandbox (two priority tasks, about 0.004 USD)')
            ->addOption('wait', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for each task on the live API', '180');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->kernelEnvironment === 'prod') {
            $io->error('Development only.');

            return self::FAILURE;
        }

        $login = $this->settings->dataforseoLogin();
        $password = $this->settings->secret(SecretName::DataforseoPassword);

        if ($login === null || $password === null) {
            $io->error('DataForSEO login and password are not configured.');

            return self::FAILURE;
        }

        $live = (bool) $input->getOption('live');
        $base = $live ? self::LIVE : self::SANDBOX;
        $directory = rtrim((string) $input->getArgument('directory'), '/');
        $prefix = $directory.'/dataforseo-'.($live ? 'live' : 'sandbox').'-';
        $auth = ['auth_basic' => [$login, $password], 'timeout' => 60];

        if (!is_dir($directory) && !mkdir($directory, 0775, true)) {
            $io->error('Cannot create '.$directory);

            return self::FAILURE;
        }

        $call = function (string $method, string $path, ?array $json, string $name) use ($base, $auth, $prefix, $io): array {
            $response = $this->httpClient->request($method, $base.$path, $auth + ($json === null ? [] : ['json' => $json]));
            $content = $response->getContent(false);
            file_put_contents($prefix.$name.'.json', json_encode(json_decode($content, true), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            $decoded = json_decode($content, true);
            $io->writeln(\sprintf('%-28s HTTP %d  status_code %s', $name, $response->getStatusCode(), \is_array($decoded) ? (string) ($decoded['status_code'] ?? '?') : 'not JSON'));

            return \is_array($decoded) ? $decoded : [];
        };

        $wait = function (string $endpoint, string $taskId) use ($call, $live, $input): void {
            if (!$live) {
                return;
            }

            $deadline = time() + (int) $input->getOption('wait');

            while (time() < $deadline) {
                $ready = $call('GET', '/merchant/google/'.$endpoint.'/tasks_ready', null, $endpoint.'-tasks_ready');

                foreach ($ready['tasks'][0]['result'] ?? [] as $entry) {
                    if (($entry['id'] ?? null) === $taskId) {
                        return;
                    }
                }

                sleep(10);
            }
        };

        $posted = $call('POST', '/merchant/google/products/task_post', [[
            'keyword' => (string) $input->getArgument('keyword'),
            'location_name' => 'France',
            'language_code' => 'fr',
            'priority' => 2,
            'depth' => 40,
            'tag' => 'capture',
        ]], 'products-task_post');

        $taskId = (string) ($posted['tasks'][0]['id'] ?? '');

        if ($taskId === '') {
            $io->error('No task id returned: see the products-task_post file.');

            return self::FAILURE;
        }

        $wait('products', $taskId);
        $call('GET', '/merchant/google/products/tasks_ready', null, 'products-tasks_ready');
        $products = $call('GET', '/merchant/google/products/task_get/advanced/'.$taskId, null, 'products-task_get_advanced');

        $productId = null;

        foreach ($products['tasks'][0]['result'][0]['items'] ?? [] as $item) {
            if (\is_string($item['product_id'] ?? null) && $item['product_id'] !== '') {
                $productId = $item['product_id'];
                break;
            }
        }

        if ($productId === null) {
            $io->warning('No product_id in the products answer: sellers not captured.');

            return self::SUCCESS;
        }

        $sellersPosted = $call('POST', '/merchant/google/sellers/task_post', [[
            'product_id' => $productId,
            'location_name' => 'France',
            'language_code' => 'fr',
            'priority' => 2,
            'tag' => 'capture',
        ]], 'sellers-task_post');

        $sellersTaskId = (string) ($sellersPosted['tasks'][0]['id'] ?? '');

        if ($sellersTaskId !== '') {
            $wait('sellers', $sellersTaskId);
            $call('GET', '/merchant/google/sellers/task_get/advanced/'.$sellersTaskId, null, 'sellers-task_get_advanced');
        }

        $io->success('Fixtures written with prefix '.$prefix);

        return self::SUCCESS;
    }
}
