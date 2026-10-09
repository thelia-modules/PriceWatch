<?php

declare(strict_types=1);

namespace PriceWatch\Controller\Admin;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Suggestion\SuggestionPresenter;
use PriceWatch\Service\Suggestion\SuggestionRun;
use PriceWatch\Service\Suggestion\SuggestionSelection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Collects the products ticked in the suggestions tab: those not watched yet
 * are added to the watch first, then one pass covers them all.
 */
final readonly class SuggestionController
{
    public function __construct(
        private AdminRequestGuard $guard,
        private ComparatorRepository $comparator,
        private SuggestionRun $suggestionRun,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/module/pricewatch/suggestions/run', name: 'pricewatch_suggestions_run', methods: ['POST'])]
    public function run(Request $request): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $productIds = SuggestionSelection::productIds($request->request->all()['products'] ?? null);

        if ($productIds === null) {
            return $this->back($request, 'warning', 'Tick %max% products at most: one pass covers them.', ['%max%' => SuggestionPresenter::MAX_SELECTED]);
        }

        if ($productIds === []) {
            return $this->back($request, 'warning', 'Tick the products to collect first.');
        }

        if ($this->comparator->competitors() === []) {
            return $this->back($request, 'warning', 'No competitor is whitelisted: there would be nothing to compare.');
        }

        try {
            $covered = $this->suggestionRun->start($productIds);
        } catch (\RuntimeException) {
            return $this->back($request, 'warning', 'A pass is already running: wait until it ends, then reload the page.');
        }

        if ($covered['products'] === 0) {
            return $this->back($request, 'warning', 'None of these products has a variant with a valid EAN code of its own: nothing can be collected.');
        }

        return $this->back($request, 'success', 'Price watch started on %products% product(s), %count% variant(s), at most %cost%. Its progress shows on the page.', [
            '%products%' => $covered['products'],
            '%count%' => $covered['variants'],
            '%cost%' => RunController::usd(ManualRun::worstCaseUsd($covered['variants']), $request->getLocale()),
        ]);
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function back(Request $request, string $type, string $message, array $parameters = []): RedirectResponse
    {
        $request->getSession()->getFlashBag()->add($type, $this->translator->trans($message, $parameters, AdminRequestGuard::MODULE_CODE));

        return new RedirectResponse($this->urlGenerator->generate('pricewatch_comparator', ['view' => 'suggestions']));
    }
}
