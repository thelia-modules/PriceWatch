<?php

declare(strict_types=1);

namespace PriceWatch\Service\Admin;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;

/**
 * One gate for every admin endpoint of the module: module permission first,
 * then the CSRF token on anything that changes state.
 */
final readonly class AdminRequestGuard
{
    public const MODULE_CODE = 'pricewatch';
    public const CSRF_TOKEN_ID = 'pricewatch_admin';

    public function __construct(
        private AdminAccessChecker $access,
        private CsrfTokenManagerInterface $csrfTokenManager,
        private SecurityContext $securityContext,
    ) {
    }

    public function denyView(): ?Response
    {
        return $this->access->check([], self::MODULE_CODE, AccessManager::VIEW);
    }

    public function denyChange(Request $request): ?Response
    {
        if ($denied = $this->access->check([], self::MODULE_CODE, AccessManager::UPDATE)) {
            return $denied;
        }

        $token = new CsrfToken(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'));

        if (!$this->csrfTokenManager->isTokenValid($token)) {
            return new Response('Invalid CSRF token', Response::HTTP_FORBIDDEN);
        }

        return null;
    }

    public function token(): string
    {
        return $this->csrfTokenManager->getToken(self::CSRF_TOKEN_ID)->getValue();
    }

    public function adminLogin(): string
    {
        $admin = $this->securityContext->getAdminUser();

        return \is_object($admin) && method_exists($admin, 'getLogin') ? (string) $admin->getLogin() : 'admin';
    }
}
