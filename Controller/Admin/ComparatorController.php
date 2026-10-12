<?php

declare(strict_types=1);

namespace PriceWatch\Controller\Admin;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Comparator\ComparatorFilters;
use PriceWatch\Service\Comparator\ComparatorPresenter;
use PriceWatch\Service\Comparator\ComparatorRepository;
use PriceWatch\Service\Comparator\OfferReview;
use PriceWatch\Service\Run\ManualRun;
use PriceWatch\Service\Run\RunStatus;
use PriceWatch\Service\Suggestion\SuggestionPresenter;
use PriceWatch\Service\Usage\UsagePresenter;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The comparator: price gaps against whitelisted competitors, and the queue
 * of offers a human has to decide on.
 */
final readonly class ComparatorController
{
    private const ACTIONS = ['confirm', 'reject', 'reopen'];

    public function __construct(
        private AdminRequestGuard $guard,
        private ComparatorPresenter $presenter,
        private UsagePresenter $usage,
        private RunStatus $runStatus,
        private ComparatorRepository $repository,
        private OfferReview $review,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private Environment $twig,
        private SuggestionPresenter $suggestions,
    ) {
    }

    #[Route('/admin/module/pricewatch/comparator', name: 'pricewatch_comparator', methods: ['GET'])]
    public function page(Request $request): Response
    {
        if ($denied = $this->guard->denyView()) {
            return $denied;
        }

        // Suggestions are products, not offers: a tab of the screen, not a
        // ComparatorView, whose tabs hold every offer exactly once.
        $suggesting = ($request->query->all()['view'] ?? null) === SuggestionPresenter::VIEW;
        $screen = $this->presenter->present(ComparatorFilters::fromRequest($request), $request->getLocale(), !$suggesting);
        // Read from all(): getInt() throws on a value that is not a number.
        $family = $request->query->all()['family'] ?? null;
        $suggestionScreen = $suggesting ? $this->suggestions->screen($request->getLocale(), null, \is_string($family) && ctype_digit($family) ? (int) $family : null) : null;
        $suggestions = $suggestionScreen['rows'] ?? null;

        if ($suggesting) {
            $screen['view'] = SuggestionPresenter::VIEW;
            $screen['tabs'] = array_map(static fn (array $tab): array => ['active' => false] + $tab, $screen['tabs']);
        }

        return new Response($this->twig->render('@PriceWatchModule/backOffice/default-twig/price-watch/comparator.html.twig', [
            ...$screen,
            'suggestions' => $suggestions,
            'suggestionCount' => $suggestionScreen === null ? $this->suggestions->count() : array_sum(array_column($suggestionScreen['families'], 'lines')),
            'suggestionFamilies' => $suggestionScreen['families'] ?? [],
            'suggestionFamily' => $suggestionScreen['family'] ?? null,
            'suggestionMax' => SuggestionPresenter::MAX_SELECTED,
            'suggestionDays' => ['sales' => SuggestionPresenter::SALES_DAYS, 'stale' => SuggestionPresenter::STALE_DAYS],
            'costPerVariant' => ManualRun::worstCaseUsd(1),
            'usage' => $this->usage->present($request->getLocale()),
            'run' => $this->runStatus->present(),
            'batches' => array_map(static fn (int $size): array => [
                'size' => $size,
                'cost' => RunController::usd(ManualRun::worstCaseUsd($size), $request->getLocale()),
            ], RunController::BATCH_SIZES),
            'csrfToken' => $this->guard->token(),
        ]));
    }

    #[Route(
        '/admin/module/pricewatch/comparator/{id}/{action}',
        name: 'pricewatch_comparator_review',
        requirements: ['id' => '\d+', 'action' => 'confirm|reject|reopen'],
        methods: ['POST'],
    )]
    public function review(Request $request, int $id, string $action): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $offer = $this->repository->find($id);

        if ($offer === null || !\in_array($action, self::ACTIONS, true)) {
            return $this->back($request, 'danger', 'This offer no longer exists.');
        }

        $reviewer = $this->guard->adminLogin();
        // A line of the screen can stand for several sizes: the decision
        // reaches those the repository confirms are on the same line.
        $also = array_map('intval', array_filter((array) $request->request->all('also'), 'is_numeric'));

        foreach ([$offer, ...$this->repository->sameLine($offer, array_values($also))] as $decided) {
            match ($action) {
                'confirm' => $this->review->confirm($decided, $reviewer),
                'reject' => $this->review->reject($decided, $reviewer),
                'reopen' => $this->review->reopen($decided, $reviewer),
            };
        }

        return $this->back($request, 'success', match ($action) {
            'confirm' => 'Offer confirmed: same product, same size. It will not be judged again.',
            'reject' => 'Offer excluded: it will not count in the gap.',
            'reopen' => 'Offer sent back to review.',
        });
    }

    private function back(Request $request, string $type, string $message): RedirectResponse
    {
        $request->getSession()->getFlashBag()->add($type, $this->translator->trans($message, [], AdminRequestGuard::MODULE_CODE));

        // The return address is rebuilt from the screen's own filters, never
        // taken as given: no open redirect through this form.
        parse_str((string) $request->request->get('return', ''), $query);
        $filters = ComparatorFilters::fromRequest(new Request(\is_array($query) ? $query : []));

        return new RedirectResponse($this->urlGenerator->generate('pricewatch_comparator', $filters->toQueryParams()));
    }
}
