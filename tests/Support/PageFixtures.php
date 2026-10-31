<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Nowo\InstanceSettingsBundle\Form\PortabilityExportType;
use Nowo\InstanceSettingsBundle\Form\PortabilityImportType;
use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Ui\SettingsPageFactory;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
use Nowo\UiKitBundle\View\Action;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Page contexts of the REQ-UI-003 contract test: view models from {@see SettingsPageFactory} (the service the controller
 * uses) and FormViews from the real form types. Secrets are obvious placeholders the pages must never print.
 */
final class PageFixtures
{
    /** Stored secret placeholder; no page may contain it. */
    public const SECRET = 'REDACTED-TEST-SECRET';

    public static function registry(): SettingsSectionRegistry
    {
        return new SettingsSectionRegistry([new MailerFixtureSection(), new GeneralFixtureSection()]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function section(string $section = 'mailer', bool $storedSecret = true): array
    {
        $registry = self::registry();
        $form     = FormFactorySupport::factory()->create(SettingsSectionType::class, $section === 'mailer' ? ['from' => 'ops@example.test', 'enabled' => true] : ['site_name' => 'Acme', 'page_size' => 20, 'mode' => 'dark'], [
            'section'        => $registry->get($section),
            'fields'         => $registry->fields($section),
            'stored_secrets' => $storedSecret ? ['dsn' => true] : [],
            'csrf_token_id'  => 'nowo_instance_settings_section_' . $section,
        ])->createView();
        $actions = $section === 'mailer' ? [Action::view('/test-mail', 'Send a test e-mail')] : [];

        return [
            'page'    => self::factory()->section($registry->all(), $section, $form, $actions, true),
            'section' => $section,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function emptyIndex(): array
    {
        return ['page' => self::factory()->empty(true)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function portability(bool $secrets = true): array
    {
        $factory = FormFactorySupport::factory();

        return ['page' => self::factory()->portability(
            $factory->create(PortabilityExportType::class, null, ['allow_secrets' => $secrets, 'action' => '/export'])->createView(),
            $factory->create(PortabilityImportType::class, null, ['action' => '/import'])->createView(),
            $secrets,
        )];
    }

    public static function factory(?SettingsPages $pages = null): SettingsPageFactory
    {
        return new SettingsPageFactory(self::urlGenerator(), $pages ?? new SettingsPages());
    }

    private static function urlGenerator(): UrlGeneratorInterface
    {
        return new class implements UrlGeneratorInterface {
            private RequestContext $context;

            public function __construct()
            {
                $this->context = new RequestContext();
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name . ($parameters === [] ? '' : '?' . http_build_query($parameters));
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }
        };
    }
}
