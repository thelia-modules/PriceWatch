<?php

declare(strict_types=1);

namespace PriceWatch\Controller\Admin;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Referential\ProductEnrolment;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Suggestion\SuggestionRun;
use PriceWatch\Service\Watched\WatchedFilters;
use PriceWatch\Service\Watched\WatchedPresenter;
use PriceWatch\Service\Watched\WatchedRepository;
use PriceWatch\Service\Watched\WatchedView;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * Every product under price watch, collected or not, and the batch actions
 * above the list (asked 2026-10-13): collect the never read or stale ones,
 * pause the ones the shop no longer sells.
 */
final readonly class WatchedController
{
    public function __construct(
        private AdminRequestGuard $guard,
        private WatchedPresenter $presenter,
        private Environment $twig,
        private WatchedRepository $watched,
        private SuggestionRun $run,
        private ProductEnrolment $enrolment,
        private ComparatorRepository $comparator,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/module/pricewatch/watched', name: 'pricewatch_watched', methods: ['GET'])]
    public function page(Request $request): Response
    {
        if ($denied = $this->guard->denyView()) {
            return $denied;
        }

        return new Response($this->twig->render('@PriceWatchModule/backOffice/default-twig/price-watch/watched.html.twig', [
            ...$this->presenter->present(WatchedFilters::fromRequest($request), $request->getLocale()),
            'csrfToken' => $this->guard->token(),
        ]));
    }

    #[Route('/admin/module/pricewatch/watched/collect/{view}', name: 'pricewatch_watched_collect', requirements: ['view' => 'never|stale'], methods: ['POST'])]
    public function collect(Request $request, string $view): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $batch = $this->watched->toCollect(WatchedView::from($view), WatchedPresenter::BATCH);

        if ($batch['productIds'] === []) {
            return $this->back($request, $view, 'warning', 'No product to collect here.');
        }

        if ($this->comparator->competitors() === []) {
            return $this->back($request, $view, 'warning', 'No competitor is whitelisted: there would be nothing to compare.');
        }

        try {
            $covered = $this->run->start($batch['productIds']);
        } catch (\RuntimeException) {
            return $this->back($request, $view, 'warning', 'A pass is already running: wait until it ends, then reload the page.');
        }

        if ($covered['products'] === 0) {
            return $this->back($request, $view, 'warning', 'None of these products has a variant with a valid EAN code of its own: nothing can be collected.');
        }

        return $this->back($request, $view, 'success', 'Price watch started on %products% product(s), %count% variant(s), at most %cost%. Its progress shows on the page.', [
            '%products%' => $covered['products'],
            '%count%' => $covered['variants'],
            '%cost%' => RunController::usd(ManualRun::worstCaseUsd($covered['variants']), $request->getLocale()),
        ]);
    }

    /**
     * Paused, not removed: history kept, "Resume" brings them back.
     */
    #[Route('/admin/module/pricewatch/watched/pause/{which}', name: 'pricewatch_watched_pause', requirements: ['which' => 'offline|out_of_stock'], methods: ['POST'])]
    public function pause(Request $request, string $which): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $productIds = $which === 'offline' ? $this->watched->offline() : $this->watched->outOfStock();
        $variants = 0;

        foreach ($productIds as $productId) {
            $variants += $this->enrolment->setMonitored($productId, false);
        }

        return $this->back($request, WatchedView::Paused->value, 'success', 'watched.paused.'.$which, ['%products%' => \count($productIds), '%count%' => $variants]);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function back(Request $request, string $view, string $type, string $message, array $parameters = []): RedirectResponse
    {
        $request->getSession()->getFlashBag()->add($type, $this->translator->trans($message, $parameters, AdminRequestGuard::MODULE_CODE));

        return new RedirectResponse($this->urlGenerator->generate('pricewatch_watched', ['view' => $view]));
    }
}
