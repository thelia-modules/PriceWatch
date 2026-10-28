<?php

declare(strict_types=1);

namespace PriceWatch\Controller\Admin;

use PriceWatch\Model\PwCompetitorOfferQuery;
use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Admin\CompetitorPagePresenter;
use PriceWatch\Service\Competitor\CompetitorDiscovery;
use PriceWatch\Service\Competitor\CompetitorRepository;
use PriceWatch\Service\Competitor\CompetitorStatus;
use PriceWatch\Service\Competitor\DiscoveryException;
use PriceWatch\Service\Normalizer\DomainNormalizer;
use PriceWatch\Service\Settings\PriceWatchSettings;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The whitelist / blacklist screen. The whitelist is what gets compared; the
 * blacklist is what is never compared and never suggested again.
 */
final readonly class CompetitorController
{
    /** Only these lists can be chosen from the screen: paused is set by collection health. */
    private const SELECTABLE = [CompetitorStatus::Watched, CompetitorStatus::Rejected];

    public function __construct(
        private AdminRequestGuard $guard,
        private CompetitorRepository $competitors,
        private CompetitorPagePresenter $presenter,
        private CompetitorDiscovery $discovery,
        private DomainNormalizer $domainNormalizer,
        private PriceWatchSettings $settings,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
        private Environment $twig,
    ) {
    }

    #[Route('/admin/module/pricewatch/competitors', name: 'pricewatch_competitors', methods: ['GET'])]
    public function page(): Response
    {
        if ($denied = $this->guard->denyView()) {
            return $denied;
        }

        return new Response($this->twig->render('@PriceWatchModule/backOffice/default-twig/price-watch/competitors.html.twig', [
            ...$this->presenter->present(),
            'csrfToken' => $this->guard->token(),
        ]));
    }

    #[Route('/admin/module/pricewatch/competitors/add', name: 'pricewatch_competitors_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $raw = trim((string) $request->request->get('domain'));
        $domain = $this->domainNormalizer->registrableDomain($raw);
        $status = $this->selectable((string) $request->request->get('list'));

        if ($domain === null || $status === null) {
            return $this->back($request, 'danger', '"%domain%" is not a usable domain name.', ['%domain%' => mb_substr($raw, 0, 120)]);
        }

        $existing = $this->competitors->findByDomain($domain);

        if ($existing !== null) {
            return $this->back($request, 'warning', '%domain% is already listed. Move it from its current list instead.', ['%domain%' => $domain]);
        }

        $this->competitors->create($domain, $status, 'manual', null, null, $this->guard->adminLogin());

        return $this->back($request, 'success', $status === CompetitorStatus::Watched
            ? '%domain% added to the whitelist.'
            : '%domain% added to the blacklist: it will never be compared nor suggested.', ['%domain%' => $domain]);
    }

    #[Route(
        '/admin/module/pricewatch/competitors/{id}/status',
        name: 'pricewatch_competitors_status',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function changeStatus(Request $request, int $id): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $competitor = $this->competitors->find($id);
        $status = $this->selectable((string) $request->request->get('status'));

        if ($competitor === null || $status === null) {
            return $this->back($request, 'danger', 'This competitor or this list does not exist.');
        }

        $this->competitors->changeStatus($competitor, $status, $this->guard->adminLogin());

        return $this->back($request, 'success', $status === CompetitorStatus::Watched
            ? '%domain% moved to the whitelist: its prices will be compared.'
            : '%domain% moved to the blacklist: no longer compared, never suggested again.', ['%domain%' => (string) $competitor->getDomain()]);
    }

    #[Route(
        '/admin/module/pricewatch/competitors/{id}/delete',
        name: 'pricewatch_competitors_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    public function delete(Request $request, int $id): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $competitor = $this->competitors->find($id);

        if ($competitor === null) {
            return $this->back($request, 'danger', 'This competitor or this list does not exist.');
        }

        // Offers cascade on delete, taking the price history with them.
        if (PwCompetitorOfferQuery::create()->filterByCompetitorId($id)->count() > 0) {
            return $this->back($request, 'danger', '%domain% has a price history: move it to the blacklist rather than deleting it.', ['%domain%' => (string) $competitor->getDomain()]);
        }

        $domain = (string) $competitor->getDomain();
        $this->competitors->delete($competitor);

        return $this->back($request, 'success', '%domain% deleted. It may be suggested again later.', ['%domain%' => $domain]);
    }

    #[Route('/admin/module/pricewatch/competitors/suggest', name: 'pricewatch_competitors_suggest', methods: ['POST'])]
    public function suggest(Request $request): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        if (!$this->settings->discoveryEnabled()) {
            return $this->back($request, 'warning', 'Competitor discovery is switched off: turn it on in the module settings first.');
        }

        // A reasoning model can take a couple of minutes.
        set_time_limit(300);

        try {
            $report = $this->discovery->suggest($request->getLocale());
        } catch (DiscoveryException $error) {
            return $this->back($request, 'danger', $error->getMessage());
        }

        return $this->back($request, $report->proposed === [] ? 'info' : 'success', '%proposed% new proposal(s). %known% already listed, %blacklisted% blacklisted, %dropped% dropped by the checks.', [
            '%proposed%' => \count($report->proposed),
            '%known%' => \count($report->alreadyKnown),
            '%blacklisted%' => \count($report->blacklisted),
            '%dropped%' => \count($report->unresolved) + \count($report->invalid),
        ]);
    }

    private function selectable(string $value): ?CompetitorStatus
    {
        $status = CompetitorStatus::tryFrom($value);

        return \in_array($status, self::SELECTABLE, true) ? $status : null;
    }

    /**
     * @param array<string, string|int> $parameters
     */
    private function back(Request $request, string $type, string $message, array $parameters = []): RedirectResponse
    {
        $request->getSession()->getFlashBag()->add($type, $this->translator->trans($message, $parameters, AdminRequestGuard::MODULE_CODE));

        return $this->redirectToPage();
    }

    private function redirectToPage(): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('pricewatch_competitors'));
    }
}
