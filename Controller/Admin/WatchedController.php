<?php

declare(strict_types=1);

namespace PriceWatch\Controller\Admin;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Watched\WatchedFilters;
use PriceWatch\Service\Watched\WatchedPresenter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Twig\Environment;

/**
 * Every product under price watch, collected or not.
 */
final readonly class WatchedController
{
    public function __construct(
        private AdminRequestGuard $guard,
        private WatchedPresenter $presenter,
        private Environment $twig,
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
}
