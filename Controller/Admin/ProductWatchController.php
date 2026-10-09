<?php

declare(strict_types=1);

namespace PriceWatch\Controller\Admin;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Admin\ReturnTo;
use PriceWatch\Service\Referential\CatalogVariantRepository;
use PriceWatch\Service\Referential\ProductEnrolment;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The price watch actions of the product page's card: add the product to
 * the watched list, pause it, resume it, as a whole or variant by variant.
 */
final readonly class ProductWatchController
{
    public function __construct(
        private AdminRequestGuard $guard,
        private ProductEnrolment $enrolment,
        private CatalogVariantRepository $catalog,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/module/pricewatch/product/{productId}/enrol', name: 'pricewatch_product_enrol', requirements: ['productId' => '\d+'], methods: ['POST'])]
    public function enrol(Request $request, int $productId): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        if (!$this->catalog->productExists($productId)) {
            return $this->back($request, $productId, 'danger', 'This product no longer exists.');
        }

        $report = $this->enrolment->enrol($productId);

        foreach ($report->refused as $refusal) {
            $request->getSession()->getFlashBag()->add('warning', $this->trans('Variant %variant% not added: %reason%', [
                '%variant%' => $refusal['variant'],
                '%reason%' => $refusal['reason'],
            ]));
        }

        return $this->back($request, $productId, $report->added + $report->resumed > 0 ? 'success' : 'info', '%added% variant(s) added to the price watch, %resumed% resumed, %already% already watched. Prices are collected at the next pass.', [
            '%added%' => $report->added,
            '%resumed%' => $report->resumed,
            '%already%' => $report->alreadyMonitored,
        ]);
    }

    #[Route(
        '/admin/module/pricewatch/product/{productId}/{action}/{saleElementId}',
        name: 'pricewatch_product_monitoring',
        requirements: ['productId' => '\d+', 'action' => 'pause|resume', 'saleElementId' => '\d+'],
        defaults: ['saleElementId' => null],
        methods: ['POST'],
    )]
    public function monitoring(Request $request, int $productId, string $action, ?int $saleElementId = null): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $changed = $this->enrolment->setMonitored($productId, $action === 'resume', $saleElementId);

        return $this->back($request, $productId, 'success', $action === 'resume'
            ? '%count% variant(s) watched again.'
            : '%count% variant(s) paused: no longer collected, their history is kept.', ['%count%' => $changed]);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function back(Request $request, int $productId, string $type, string $message, array $parameters = []): RedirectResponse
    {
        $request->getSession()->getFlashBag()->add($type, $this->trans($message, $parameters));

        return new RedirectResponse(
            ReturnTo::url($request, $this->urlGenerator)
            ?? $this->urlGenerator->generate('admin.products.update', ['product_id' => $productId, 'current_tab' => 'modules']),
        );
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function trans(string $message, array $parameters = []): string
    {
        return $this->translator->trans($message, $parameters, AdminRequestGuard::MODULE_CODE);
    }
}
