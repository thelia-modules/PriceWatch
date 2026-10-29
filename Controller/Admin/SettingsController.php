<?php

declare(strict_types=1);

namespace PriceWatch\Controller\Admin;

use PriceWatch\Service\Admin\AdminRequestGuard;
use PriceWatch\Service\Schedule\ScheduleSettings;
use PriceWatch\Service\Schedule\WatchTier;
use PriceWatch\Service\Settings\ConnectionCheck;
use PriceWatch\Service\Settings\InvalidSettingException;
use PriceWatch\Service\Settings\JevProvider;
use PriceWatch\Service\Settings\PriceWatchSettings;
use PriceWatch\Service\Settings\SecretName;
use PriceWatch\Service\Settings\SecretSource;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class SettingsController
{
    public function __construct(
        private AdminRequestGuard $guard,
        private PriceWatchSettings $settings,
        private ScheduleSettings $schedule,
        private ConnectionCheck $connectionCheck,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    #[Route('/admin/module/pricewatch/settings/save', name: 'pricewatch_settings_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $errors = [];
        $attempt = static function (callable $change) use (&$errors): void {
            try {
                $change();
            } catch (InvalidSettingException $error) {
                $errors[] = $error->getMessage();
            }
        };

        $provider = JevProvider::tryFrom((string) $request->request->get('jev_provider'));
        if ($provider !== null) {
            $this->settings->setJevProvider($provider);
        }

        foreach (SecretName::cases() as $name) {
            // A key provisioned by the server environment is not editable here.
            if ($this->settings->secretSource($name) === SecretSource::Environment) {
                continue;
            }

            if ($request->request->get($name->value.'_clear') === '1') {
                $this->settings->clearSecret($name);
                continue;
            }

            $value = (string) $request->request->get($name->value, '');

            // Empty means "keep what is stored": the field never shows the key.
            if (trim($value) !== '') {
                $attempt(fn () => $this->settings->storeSecret($name, $value));
            }
        }

        if (!$this->settings->dataforseoLoginFromEnvironment()) {
            $attempt(fn () => $this->settings->setDataforseoLogin((string) $request->request->get('dataforseo_login', '')));
        }

        $this->settings->setExcludedFamilies(array_map('intval', array_filter((array) $request->request->all('excluded_families'), 'is_numeric')));
        $attempt(fn () => $this->settings->setMinimumMarginPct((string) $request->request->get('minimum_margin_pct', '')));

        $this->schedule->setEnabled($request->request->get('schedule_enabled') === '1');
        foreach (WatchTier::cases() as $tier) {
            $attempt(fn () => $this->schedule->setDays($tier, (string) $request->request->get('schedule_days_'.$tier->value, '')));
        }
        $attempt(fn () => $this->schedule->setHours((string) $request->request->get('schedule_start_hour', ''), (string) $request->request->get('schedule_end_hour', '')));
        $attempt(fn () => $this->schedule->setMaxVariants((string) $request->request->get('schedule_max_variants', '')));

        foreach ($errors as $error) {
            $this->flash($request, 'danger', $error);
        }

        if ($errors === []) {
            $this->flash($request, 'success', 'Settings saved.');
        }

        return $this->backToSettings();
    }

    #[Route(
        '/admin/module/pricewatch/settings/test/{service}',
        name: 'pricewatch_settings_test',
        requirements: ['service' => 'openrouter|typesafe|dataforseo'],
        methods: ['POST'],
    )]
    public function test(Request $request, string $service): Response
    {
        if ($denied = $this->guard->denyChange($request)) {
            return $denied;
        }

        $result = match ($service) {
            'openrouter' => $this->connectionCheck->openRouter(),
            'typesafe' => $this->connectionCheck->typesafe(),
            'dataforseo' => $this->connectionCheck->dataforseo(),
        };

        // ConnectionCheck never puts a key in its messages; fixed ones are
        // translated, those carrying a status code stay as they are.
        $this->flash($request, $result['ok'] ? 'success' : 'danger', $result['message']);

        return $this->backToSettings();
    }

    /**
     * @param array<string, int|string> $parameters
     */
    private function flash(Request $request, string $type, string $message, array $parameters = []): void
    {
        $request->getSession()->getFlashBag()->add($type, $this->translator->trans($message, $parameters, AdminRequestGuard::MODULE_CODE));
    }

    private function backToSettings(): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate('admin.module.configure', ['module_code' => 'PriceWatch']));
    }
}
