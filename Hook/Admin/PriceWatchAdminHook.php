<?php

declare(strict_types=1);

namespace PriceWatch\Hook\Admin;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Comparator\ProductCardPresenter;
use PriceWatch\Service\Settings\JevProvider;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\ReasoningEffort;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Service\Usage\UsagePresenter;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Core\Event\Hook\HookRenderBlockEvent;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Template\ParserResolver;
use Twig\Environment;

/**
 * Every hook point was checked present in the hook table before subscribing:
 * a hook missing from that table is dropped silently, with no error and no
 * output. main.top-menu-catalog is a block hook, hence HookRenderBlockEvent as
 * first parameter; a wrong type there disables the subscription silently too.
 */
class PriceWatchAdminHook extends BaseHook
{
    public function __construct(
        private readonly SecurityContext $securityContext,
        private readonly PriceWatchSettings $settings,
        private readonly AdminRequestGuard $guard,
        private readonly ProductCardPresenter $productCard,
        private readonly UsagePresenter $usage,
        private readonly RequestStack $requestStack,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly Environment $twig,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    public static function getSubscribedHooks(): array
    {
        return [
            'main.top-menu-catalog' => [['type' => 'back', 'method' => 'onCatalogMenu']],
            'module.configuration' => [['type' => 'back', 'method' => 'onModuleConfiguration']],
            'product.tab-content' => [['type' => 'back', 'method' => 'onProductTabContent']],
        ];
    }

    public function onCatalogMenu(HookRenderBlockEvent $event): void
    {
        if (!$this->securityContext->isGranted(['ADMIN'], [], [AdminRequestGuard::MODULE_CODE], [AccessManager::VIEW])) {
            return;
        }

        $event->add([
            'id' => 'pricewatch-comparator',
            'class' => '',
            'url' => $this->urlGenerator->generate('pricewatch_comparator'),
            'title' => $this->trans('Price watch: comparator', [], AdminRequestGuard::MODULE_CODE),
        ]);

        $event->add([
            'id' => 'pricewatch-competitors',
            'class' => '',
            'url' => $this->urlGenerator->generate('pricewatch_competitors'),
            'title' => $this->trans('Price watch: competitors', [], AdminRequestGuard::MODULE_CODE),
        ]);
    }

    /**
     * Rendered in the product page's Modules tab, framed by hook_cards.
     */
    public function onProductTabContent(HookRenderEvent $event): void
    {
        $productId = (int) $event->getArgument('product');

        if ($productId <= 0 || !$this->securityContext->isGranted(['ADMIN'], [], [AdminRequestGuard::MODULE_CODE], [AccessManager::VIEW])) {
            return;
        }

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'fr_FR';

        $event->add($this->twig->render('@PriceWatchModule/backOffice/default-twig/hook/product-card.html.twig', [
            ...$this->productCard->present($productId, $locale),
            'csrfToken' => $this->guard->token(),
            'canChange' => $this->securityContext->isGranted(['ADMIN'], [], [AdminRequestGuard::MODULE_CODE], [AccessManager::UPDATE]),
        ]));
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        if (strtolower((string) $event->getArgument('modulecode')) !== AdminRequestGuard::MODULE_CODE) {
            return;
        }

        $secrets = [];

        foreach (SecretName::cases() as $name) {
            $secrets[$name->value] = [
                'field' => $name->value,
                'source' => $this->settings->secretSource($name)->value,
                'updatedAt' => $this->settings->secretUpdatedAt($name)?->format('d/m/Y H:i'),
                'environmentVariable' => $name->environmentVariable(),
            ];
        }

        $event->add($this->twig->render('@PriceWatchModule/backOffice/default-twig/hook/module-configuration.html.twig', [
            'csrfToken' => $this->guard->token(),
            'usage' => $this->usage->present($this->requestStack->getCurrentRequest()?->getLocale() ?? 'fr_FR'),
            'secrets' => $secrets,
            'jevProvider' => $this->settings->jevProvider()->value,
            'jevProviders' => array_map(static fn (JevProvider $provider): string => $provider->value, JevProvider::cases()),
            'jevReady' => $this->settings->isJevReady(),
            'llmReady' => $this->settings->isLlmReady(),
            'llmModel' => $this->settings->llmModel(),
            'modelList' => $this->settings->modelList(),
            'reasoningEffort' => $this->settings->reasoningEffort()->value,
            'reasoningEfforts' => array_map(static fn (ReasoningEffort $effort): string => $effort->value, ReasoningEffort::cases()),
            'webSearch' => $this->settings->webSearchEnabled(),
            'shopDescription' => $this->settings->shopDescription(),
            'dataforseoLogin' => $this->settings->dataforseoLoginFromEnvironment() ? null : $this->settings->dataforseoLogin(),
            'dataforseoLoginFromEnvironment' => $this->settings->dataforseoLoginFromEnvironment(),
        ]));
    }
}
