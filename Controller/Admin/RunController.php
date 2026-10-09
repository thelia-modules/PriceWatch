<?php

declare(strict_types=1);

namespace PriceWatch\Controller\Admin;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Admin\ReturnTo;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Referential\CatalogVariantRepository;
use PriceWatch\Service\Run\ManualRun;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Starts a price watch pass by hand: collect then judge, in the background.
 */
final readonly class RunController
{
    /** The sizes offered on the comparator, so the cost is always known in advance. */
    public const BATCH_SIZES = [10, 50, 100, 500];

    public function __construct(
        private AdminRequestGuard $guard,
        private ManualRun $manualRun,
        private CatalogVariantRepository $catalog,
        private ComparatorRepository $comparator,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/module/pricewatch/run', name: 'pricewatch_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $back = $this->urlGenerator->generate('pricewatch_comparator');
        $variants = (int) $request->request->get('variants');

        if (!\in_array($variants, self::BATCH_SIZES, true)) {
            return $this->redirect($request, $back, 'danger', 'Choose how many variants to collect.');
        }

        return $this->start($request, $back, $variants, null);
    }

    #[Route('/admin/module/pricewatch/product/{productId}/collect', name: 'pricewatch_product_collect', requirements: ['productId' => '\d+'], methods: ['POST'])]
    public function product(Request $request, int $productId): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $back = ReturnTo::url($request, $this->urlGenerator)
            ?? $this->urlGenerator->generate('admin.products.update', ['product_id' => $productId, 'current_tab' => 'modules']);
        $ref = $this->catalog->productRef($productId);
        $variants = $this->catalog->collectableVariantCount($productId);

        if ($ref === null || $variants === 0) {
            return $this->redirect($request, $back, 'warning', 'No variant of this product can be collected: watch it first, with a valid EAN code of its own.');
        }

        return $this->start($request, $back, $variants, $ref);
    }

    private function start(Request $request, string $back, int $variants, ?string $productRef): RedirectResponse
    {
        if ($this->comparator->competitors() === []) {
            return $this->redirect($request, $back, 'warning', 'No competitor is whitelisted: there would be nothing to compare.');
        }

        try {
            $this->manualRun->start($variants, $productRef);
        } catch (\RuntimeException) {
            return $this->redirect($request, $back, 'warning', 'A pass is already running: wait until it ends, then reload the page.');
        }

        return $this->redirect($request, $back, 'success', 'Price watch started on %count% variant(s), at most %cost%. Results come in within a few minutes: reload the page.', [
            '%count%' => $variants,
            '%cost%' => self::usd(ManualRun::worstCaseUsd($variants), $request->getLocale()),
        ]);
    }

    public static function usd(float $amount, string $locale): string
    {
        $money = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
        $money->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 3);

        return (string) $money->formatCurrency($amount, 'USD');
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function redirect(Request $request, string $url, string $type, string $message, array $parameters = []): RedirectResponse
    {
        $request->getSession()->getFlashBag()->add($type, $this->translator->trans($message, $parameters, AdminRequestGuard::MODULE_CODE));

        return new RedirectResponse($url);
    }
}
