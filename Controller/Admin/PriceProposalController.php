<?php

declare(strict_types=1);

namespace PriceWatch\Controller\Admin;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Proposal\PriceProposalPresenter;
use PriceWatch\Service\Proposal\ProposalCsv;
use PriceWatch\Service\Proposal\ProposalKind;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Price proposals (asked 2026-10-20): where a competitor sells cheaper, the
 * price to align on, never below the margin floor set from the purchase
 * price, and the sales trend beside it. Read only: nothing is written to the
 * catalogue prices, the change is made on the product page.
 */
final readonly class PriceProposalController
{
    public function __construct(
        private AdminRequestGuard $guard,
        private PriceProposalPresenter $presenter,
        private ProposalCsv $csv,
        private Environment $twig,
    ) {
    }

    #[Route('/admin/module/pricewatch/proposals', name: 'pricewatch_proposals', methods: ['GET'])]
    public function page(Request $request): Response
    {
        if ($denied = $this->guard->denyView()) {
            return $denied;
        }

        $view = ProposalKind::tryFrom((string) ($request->query->all()['view'] ?? '')) ?? ProposalKind::LowerDeclining;

        return new Response($this->twig->render('@PriceWatchModule/backOffice/default-twig/price-watch/proposals.html.twig',
            $this->presenter->present($view, $request->getLocale(), new \DateTimeImmutable()),
        ));
    }

    #[Route('/admin/module/pricewatch/proposals.csv', name: 'pricewatch_proposals_csv', methods: ['GET'])]
    public function export(Request $request): Response
    {
        if ($denied = $this->guard->denyView()) {
            return $denied;
        }

        $now = new \DateTimeImmutable();

        return new Response($this->csv->write($this->presenter->lines($request->getLocale(), $now)), Response::HTTP_OK, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="propositions-prix-'.$now->format('Y-m-d').'.csv"',
        ]);
    }
}
