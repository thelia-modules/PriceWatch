<?php

declare(strict_types=1);

namespace PriceWatch\Command;

use PriceWatch\Model\PwCompetitorPageQuery;
use PriceWatch\Model\PwCompetitorQuery;
use PriceWatch\Service\Direct\PageRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Thelia\Model\ProductQuery;

#[AsCommand(
    name: 'pricewatch:page',
    description: 'Ties competitor product pages to our products for pricewatch:direct: list, add, confirm, reject, or seed them from the merchant pages Google Shopping led to.',
)]
final class PageCommand extends Command
{
    private const ACTIONS = ['list', 'add', 'confirm', 'reject', 'seed'];

    public function __construct(private readonly PageRepository $pages)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, implode('|', self::ACTIONS))
            ->addOption('competitor', null, InputOption::VALUE_REQUIRED, 'Competitor domain (add, list)')
            ->addOption('product', null, InputOption::VALUE_REQUIRED, 'Our product ref (add, list)')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Page address (add)')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'Page id (confirm, reject)')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'add: a human vouches the page sells this very product and colour');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        return match ((string) $input->getArgument('action')) {
            'list' => $this->list($io, $input),
            'add' => $this->add($io, $input),
            'confirm' => $this->decide($io, $input, PageRepository::CONFIRMED),
            'reject' => $this->decide($io, $input, PageRepository::REJECTED),
            'seed' => $this->seed($io),
            default => $this->invalid($io, 'Unknown action. Use one of: '.implode(', ', self::ACTIONS)),
        };
    }

    private function list(SymfonyStyle $io, InputInterface $input): int
    {
        $query = PwCompetitorPageQuery::create()->orderByCompetitorId()->orderByProductId();

        if (\is_string($input->getOption('competitor'))) {
            $query->usePwCompetitorQuery()->filterByDomain($input->getOption('competitor'))->endUse();
        }

        if (\is_string($input->getOption('product'))) {
            $query->useProductQuery()->filterByRef($input->getOption('product'))->endUse();
        }

        $rows = [];

        foreach ($query->find() as $page) {
            $rows[] = [
                $page->getId(),
                PwCompetitorQuery::create()->findPk($page->getCompetitorId())?->getDomain(),
                ProductQuery::create()->findPk($page->getProductId())?->getRef(),
                $page->getStatus().($page->getConfirmedBy() === null ? '' : ' ('.$page->getConfirmedBy().')'),
                trim($page->getLastOutcome().' '.$page->getLastError()),
                $page->getUrl(),
            ];
        }

        $io->table(['Id', 'Competitor', 'Product', 'Status', 'Last outcome', 'URL'], $rows);

        return self::SUCCESS;
    }

    private function add(SymfonyStyle $io, InputInterface $input): int
    {
        $domain = $input->getOption('competitor');
        $ref = $input->getOption('product');
        $url = $input->getOption('url');

        if (!\is_string($domain) || !\is_string($ref) || !\is_string($url) || preg_match('#^https?://#i', $url) !== 1) {
            return $this->invalid($io, 'add needs --competitor=<domain> --product=<ref> --url=<https://...>.');
        }

        $competitor = PwCompetitorQuery::create()->findOneByDomain($domain);
        $product = ProductQuery::create()->findOneByRef($ref);

        if ($competitor === null || $product === null) {
            return $this->invalid($io, $competitor === null ? 'Unknown competitor: '.$domain : 'Unknown product ref: '.$ref);
        }

        $page = $this->pages->add((int) $competitor->getId(), (int) $product->getId(), $url, 'manual');

        if ($input->getOption('confirm')) {
            $this->pages->setStatus($page, PageRepository::CONFIRMED, 'manual');
        }

        $io->success(\sprintf('Page %d: %s.', $page->getId(), $page->getStatus()));

        return self::SUCCESS;
    }

    private function decide(SymfonyStyle $io, InputInterface $input, string $status): int
    {
        $page = PwCompetitorPageQuery::create()->findPk((int) $input->getOption('id'));

        if ($page === null) {
            return $this->invalid($io, 'Unknown page: give --id.');
        }

        $this->pages->setStatus($page, $status, 'manual');
        $io->success(\sprintf('Page %d: %s.', $page->getId(), $status));

        return self::SUCCESS;
    }

    private function seed(SymfonyStyle $io): int
    {
        $io->success(\sprintf('%d candidate page(s) added from the merchant pages Google Shopping led to.', $this->pages->seedFromShoppingOffers()));

        return self::SUCCESS;
    }

    private function invalid(SymfonyStyle $io, string $message): int
    {
        $io->error($message);

        return self::INVALID;
    }
}
