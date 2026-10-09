<?php

declare(strict_types=1);

namespace PriceWatch\Command\Dev;

use PriceWatch\Service\Dev\CatalogExportReader;
use PriceWatch\Service\Import\CatalogCsvReader;
use PriceWatch\Service\Import\ReferenceFileFormat;
use Propel\Runtime\Propel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Attribute;
use Thelia\Model\AttributeAv;
use Thelia\Model\AttributeAvI18nQuery;
use Thelia\Model\AttributeCombination;
use Thelia\Model\AttributeI18nQuery;
use Thelia\Model\Category;
use Thelia\Model\CategoryI18nQuery;
use Thelia\Model\CategoryQuery;
use Thelia\Model\CurrencyQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductCategoryQuery;
use Thelia\Model\ProductQuery;
use Thelia\Model\ProductSaleElementsQuery;
use Thelia\Model\TaxRuleQuery;

/**
 * Development only: builds a test catalogue from the shop's product export,
 * so the reference file has real products and real GTINs to attach to.
 *
 * Everything it creates sits under one dedicated category, which is what
 * --purge removes. Each product is created in its own transaction: an
 * interrupted run leaves whole products or none, and a rerun skips the refs
 * that already exist.
 */
#[AsCommand(
    name: 'pricewatch:dev:seed-catalog',
    description: 'DEVELOPMENT ONLY. Creates test products from the shop catalogue export, under one dedicated category.',
)]
final class SeedCatalogCommand extends Command
{
    public const CATEGORY_TITLE = 'PriceWatch : catalogue de test';
    private const LOCALE = 'fr_FR';

    /** @var array<string, int> */
    private array $attributeIds = [];

    /** @var array<string, int> */
    private array $valueIds = [];

    public function __construct(
        private readonly CatalogExportReader $reader,
        private readonly RequestStack $requestStack,
        // Bound by the core to %kernel.environment%: the name is the binding.
        private readonly string $kernelEnvironment,
        // Saving a category or a product fires listeners that build rewritten
        // URLs through the URL and Translator singletons, which only exist once
        // their services are built. Injecting them builds them, exactly as
        // Thelia\Test\IntegrationTestCase does in setUp().
        #[Autowire(service: 'thelia.url.manager')]
        private readonly object $urlManager,
        #[Autowire(service: 'thelia.translator')]
        private readonly object $theliaTranslator,
    ) {
        parent::__construct();
    }

    /**
     * Listeners on model saves read the request and its session
     * unconditionally; in CLI there is none.
     */
    private function ensureRequest(): void
    {
        if ($this->requestStack->getCurrentRequest() !== null) {
            return;
        }

        $request = Request::create('http://localhost');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $this->requestStack->push($request);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::OPTIONAL, 'Catalogue export (ref, gtin, title, axis, value, price)')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Only the first N products of the file')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'Delete every product of the test category, then the category')
            ->addOption('write-reference', null, InputOption::VALUE_REQUIRED, 'Also write the PriceWatch reference file for these products to this path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->kernelEnvironment === 'prod') {
            $io->error('This command writes test products into the catalogue. It never runs in production.');

            return self::FAILURE;
        }

        Propel::disableInstancePooling();
        $this->ensureRequest();

        if ($input->getOption('purge')) {
            return $this->purge($io);
        }

        $file = $input->getArgument('file');

        if (!\is_string($file) || !is_file($file)) {
            $io->error('Give the path of the catalogue export.');

            return self::INVALID;
        }

        $parsed = $this->reader->read($file);
        $products = $parsed['products'];
        $limit = $input->getOption('limit');

        if (is_numeric($limit)) {
            $products = \array_slice($products, 0, max(1, (int) $limit), true);
        }

        $categoryId = $this->testCategoryId();
        $taxRuleId = (int) (TaxRuleQuery::create()->filterByIsDefault(true)->findOne() ?? TaxRuleQuery::create()->findOne())?->getId();
        $currencyId = (int) CurrencyQuery::create()->filterByByDefault(1)->findOne()?->getId();

        if ($taxRuleId === 0 || $currencyId === 0) {
            $io->error('No default tax rule or default currency in this install.');

            return self::FAILURE;
        }

        $created = $skipped = $failed = $variants = 0;
        $errors = [];
        $connection = Propel::getConnection('TheliaMain');
        $io->progressStart(\count($products));

        foreach ($products as $ref => $product) {
            $io->progressAdvance();

            if (ProductQuery::create()->filterByRef((string) $ref)->count() > 0) {
                ++$skipped;
                continue;
            }

            $connection->beginTransaction();

            try {
                $variants += $this->createProduct((string) $ref, $product, $categoryId, $taxRuleId, $currencyId, $created);
                $connection->commit();
                ++$created;
            } catch (\Throwable $error) {
                $connection->rollBack();
                ++$failed;

                if (\count($errors) < 10) {
                    $errors[] = $ref.': '.mb_substr($error->getMessage(), 0, 160);
                }
            }
        }

        $io->progressFinish();
        $io->table(['', ''], [
            ['Lines read', $parsed['lines']],
            ['Products in file (after --limit)', \count($products)],
            ['Products created', $created],
            ['Variants created', $variants],
            ['Already present, skipped', $skipped],
            ['Failed, rolled back', $failed],
            ['Axis conflicts in the export', $parsed['axisConflicts']],
        ]);

        if ($errors !== []) {
            $io->warning($errors);
        }

        $referencePath = $input->getOption('write-reference');

        if (\is_string($referencePath) && $referencePath !== '') {
            $io->writeln(\sprintf('%d reference line(s) written to %s', $this->writeReference($referencePath, $products), $referencePath));
        }

        $io->note(\sprintf('Everything sits under the category "%s". Remove it all with --purge.', self::CATEGORY_TITLE));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param array{title: string, variants: list<array{gtin: string, price: string, axes: array<string, string>}>} $product
     */
    private function createProduct(string $ref, array $product, int $categoryId, int $taxRuleId, int $currencyId, int $position): int
    {
        $first = $product['variants'][0];
        $connection = Propel::getConnection('TheliaMain');

        $model = new Product();
        $model->setRef($ref)->setVisible(1)->setPosition($position + 1);
        $model->setLocale(self::LOCALE)->setTitle($product['title'] !== '' ? $product['title'] : $ref);
        $model->create($categoryId, (float) $first['price'], $currencyId, $taxRuleId, 0.0, 10);

        // Product::create() made the default variant: give it the first
        // variant's identity rather than creating a second one.
        $default = ProductSaleElementsQuery::create()->filterByProductId($model->getId())->findOne();

        if ($default === null) {
            throw new \RuntimeException('Product::create() made no default variant.');
        }

        $default->setRef($this->variantRef($ref, $first))->setEanCode($first['gtin'])->save($connection);
        $this->combine((int) $default->getId(), $first['axes']);

        foreach (\array_slice($product['variants'], 1) as $variant) {
            $saleElement = $model->createProductSaleElement(
                $connection,
                0.0,
                (float) $variant['price'],
                (float) $variant['price'],
                $currencyId,
                false,
                false,
                false,
                10,
                $variant['gtin'],
                $this->variantRef($ref, $variant),
            );
            $this->combine((int) $saleElement->getId(), $variant['axes']);
        }

        return \count($product['variants']);
    }

    /**
     * @param array{gtin: string, price: string, axes: array<string, string>} $variant
     */
    private function variantRef(string $ref, array $variant): string
    {
        return mb_substr($ref.'-'.($variant['gtin'] !== '' ? $variant['gtin'] : implode('-', $variant['axes'])), 0, 255);
    }

    /**
     * @param array<string, string> $axes
     */
    private function combine(int $saleElementId, array $axes): void
    {
        foreach ($axes as $axis => $value) {
            $attributeId = $this->attributeId($axis);

            (new AttributeCombination())
                ->setProductSaleElementsId($saleElementId)
                ->setAttributeId($attributeId)
                ->setAttributeAvId($this->valueId($attributeId, $value))
                ->save();
        }
    }

    private function attributeId(string $title): int
    {
        if (isset($this->attributeIds[$title])) {
            return $this->attributeIds[$title];
        }

        $existing = AttributeI18nQuery::create()->filterByLocale(self::LOCALE)->filterByTitle($title)->findOne();

        if ($existing !== null) {
            return $this->attributeIds[$title] = (int) $existing->getId();
        }

        $attribute = new Attribute();
        $attribute->setLocale(self::LOCALE)->setTitle($title)->save();

        return $this->attributeIds[$title] = (int) $attribute->getId();
    }

    private function valueId(int $attributeId, string $title): int
    {
        $key = $attributeId.'|'.$title;

        if (isset($this->valueIds[$key])) {
            return $this->valueIds[$key];
        }

        $existing = AttributeAvI18nQuery::create()
            ->filterByLocale(self::LOCALE)
            ->filterByTitle($title)
            ->useAttributeAvQuery()
                ->filterByAttributeId($attributeId)
            ->endUse()
            ->findOne();

        if ($existing !== null) {
            return $this->valueIds[$key] = (int) $existing->getId();
        }

        $value = new AttributeAv();
        $value->setAttributeId($attributeId)->setPosition(\count($this->valueIds) + 1);
        $value->setLocale(self::LOCALE)->setTitle($title)->save();

        return $this->valueIds[$key] = (int) $value->getId();
    }

    private function testCategoryId(): int
    {
        $existing = CategoryI18nQuery::create()->filterByLocale(self::LOCALE)->filterByTitle(self::CATEGORY_TITLE)->findOne();

        if ($existing !== null) {
            return (int) $existing->getId();
        }

        $category = new Category();
        $category->setParent(0)->setVisible(1)->setPosition((int) CategoryQuery::create()->count() + 1);
        $category->setLocale(self::LOCALE)->setTitle(self::CATEGORY_TITLE)->save();

        return (int) $category->getId();
    }

    /**
     * One line per variant, attached by GTIN when there is one and by its
     * attribute combination otherwise: exactly what a merchant would write.
     *
     * @param array<string, array{title: string, variants: list<array{gtin: string, price: string, axes: array<string, string>}>}> $products
     */
    private function writeReference(string $path, array $products): int
    {
        $handle = fopen($path, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Cannot write '.$path);
        }

        fwrite($handle, ReferenceFileFormat::header()."\n");
        $written = 0;

        foreach ($products as $ref => $product) {
            foreach ($product['variants'] as $variant) {
                $pairs = [];

                foreach ($variant['axes'] as $axis => $value) {
                    // ":" and "|" are the pair syntax: never let a label carry them.
                    $pairs[] = str_replace([':', '|'], ' ', $axis).':'.str_replace('|', '/', $value);
                }

                $row = array_fill_keys(ReferenceFileFormat::COLUMNS, '');
                $row['product_ref'] = (string) $ref;
                $row['ean_code'] = $variant['gtin'];
                $row['attribute_combination'] = implode('|', $pairs);
                $row['monitored'] = '1';
                $row['locale'] = self::LOCALE;

                fputcsv($handle, array_values($row), CatalogCsvReader::DELIMITER, CatalogCsvReader::ENCLOSURE, '');
                ++$written;
            }
        }

        fclose($handle);

        return $written;
    }

    private function purge(SymfonyStyle $io): int
    {
        $category = CategoryI18nQuery::create()->filterByLocale(self::LOCALE)->filterByTitle(self::CATEGORY_TITLE)->findOne();

        if ($category === null) {
            $io->success('No test category: nothing to purge.');

            return self::SUCCESS;
        }

        $productIds = ProductCategoryQuery::create()
            ->filterByCategoryId($category->getId())
            ->filterByDefaultCategory(true)
            ->select(['ProductId'])
            ->find()
            ->getData();

        $deleted = 0;

        foreach (array_chunk($productIds, 200) as $chunk) {
            // Variants, prices, combinations and PriceWatch identities go with
            // their product through ON DELETE CASCADE.
            foreach (ProductQuery::create()->filterById($chunk)->find() as $product) {
                $product->delete();
                ++$deleted;
            }
        }

        CategoryQuery::create()->findPk($category->getId())?->delete();

        $io->success(\sprintf('%d test product(s) and the test category deleted.', $deleted));

        return self::SUCCESS;
    }
}
