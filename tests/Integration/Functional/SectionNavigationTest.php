<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\EmbedHostController;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function substr_count;

/**
 * 1.16 (Beacon): `web_ui.navigation_hidden` leaves sections out of the bundle tab bar and of the "first section"
 * redirect without touching access, and `web_ui.section_icons` overrides or fills tab icons.
 */
final class SectionNavigationTest extends KernelTestCase
{
    public function testDefaultsChangeNothing(): void
    {
        $client = $this->client($this->bootKernel());

        $client->request('GET', '/admin/settings');
        self::assertSame('/admin/settings/sections/mailer', $client->getResponse()->headers->get('Location'));
        $client->request('GET', '/admin/settings/sections/general');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('href="/admin/settings/sections/mailer"', $html);
        self::assertSame(1, substr_count($html, 'nowo-ui-tabs__icon'), 'only the mailer fixture declares an icon');
    }

    public function testTheTabBarAndTheRedirectSkipAHiddenSection(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['navigation_hidden' => ['mailer']]]);
        $client = $this->client($kernel);

        $client->request('GET', '/admin/settings');
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/admin/settings/sections/general', $client->getResponse()->headers->get('Location'), 'the first visible section');

        $client->request('GET', '/admin/settings/sections/general');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('/admin/settings/sections/mailer', (string) $client->getResponse()->getContent(), 'no tab for the hidden section');

        // Its own URL keeps working: the form renders, with the other sections' tabs.
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('form[name="settings_section"]'));
        self::assertStringContainsString('Sender address', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('href="/admin/settings/sections/general"', (string) $client->getResponse()->getContent());

        $client->submit($crawler->filter('form[name="settings_section"]')->form(), ['settings_section[from]' => 'hidden@example.test']);
        self::assertSame('/admin/settings/sections/mailer', $client->getResponse()->headers->get('Location'));
        self::assertSame('hidden@example.test', $this->settings($kernel)->get('mailer.from'));
    }

    public function testHidingNeverGrantsNorDenies(): void
    {
        $kernel = $this->bootKernel([
            'web_ui'   => ['navigation_hidden' => ['mailer']],
            'security' => ['area_roles' => ['mailer' => ['ROLE_SUPER_ADMIN'], '_portability' => ['ROLE_SUPER_ADMIN']]],
        ]);

        $admin = $this->client($kernel, 'admin');
        $admin->request('GET', '/admin/settings/sections/mailer');
        self::assertSame(403, $admin->getResponse()->getStatusCode(), 'the guard of a hidden section is unchanged');
        $admin->request('POST', '/admin/settings/sections/mailer', ['settings_section' => ['from' => 'x@example.test']]);
        self::assertSame(403, $admin->getResponse()->getStatusCode());

        $super = $this->client($kernel, 'super');
        $super->request('GET', '/admin/settings/sections/mailer');
        self::assertSame(200, $super->getResponse()->getStatusCode());

        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', '/admin/settings/sections/mailer');
        self::assertSame(401, $anonymous->getResponse()->getStatusCode());
    }

    public function testWhenEveryOpenSectionIsHiddenTheRedirectKeepsTheBehaviourBefore116(): void
    {
        $all = $this->client($this->bootKernel(['web_ui' => ['navigation_hidden' => ['mailer', 'general']]]));
        $all->request('GET', '/admin/settings');
        self::assertSame('/admin/settings/sections/mailer', $all->getResponse()->headers->get('Location'));

        // The only visible section is denied to the admin: the first open (hidden) one, never the denied one.
        $kernel = $this->bootKernel([
            'web_ui'   => ['navigation_hidden' => ['mailer']],
            'security' => ['area_roles' => ['general' => ['ROLE_SUPER_ADMIN'], '_portability' => ['ROLE_SUPER_ADMIN']]],
        ]);
        $admin = $this->client($kernel, 'admin');
        $admin->request('GET', '/admin/settings');
        self::assertSame('/admin/settings/sections/mailer', $admin->getResponse()->headers->get('Location'));
    }

    public function testSectionIconsOverrideAndFillAndUnknownNamesAreIgnored(): void
    {
        $kernel = $this->bootKernel(
            ['web_ui' => ['section_icons' => ['general' => 'config', 'mailer' => 'export', 'nope' => 'config'], 'navigation_hidden' => ['gone']]],
            ['logger' => RecordingLogger::class],
        );
        $client = $this->client($kernel);

        $client->request('GET', '/admin/settings');
        self::assertSame('/admin/settings/sections/mailer', $client->getResponse()->headers->get('Location'), 'an unknown hidden name changes nothing');
        $client->request('GET', '/admin/settings/sections/general');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame(2, substr_count((string) $client->getResponse()->getContent(), 'nowo-ui-tabs__icon'), 'general filled, mailer overridden');

        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        self::assertStringContainsString('nowo_instance_settings.web_ui.section_icons', $logger->dump());
        self::assertStringContainsString('nope', $logger->dump());
        self::assertStringContainsString('gone', $logger->dump());
    }

    public function testAHiddenSectionIsStillEmbeddableAndGuarded(): void
    {
        $kernel = $this->bootKernel(
            [
                'files'    => ['enabled' => true],
                'web_ui'   => ['navigation_hidden' => ['general', 'brand']],
                'security' => ['area_roles' => ['general' => ['ROLE_USER', 'ROLE_ADMIN'], 'mailer' => ['ROLE_SUPER_ADMIN'], 'brand' => ['ROLE_SUPER_ADMIN'], '_portability' => ['ROLE_SUPER_ADMIN']]],
            ],
            [ImageFixtureSection::class => ImageFixtureSection::class, EmbedHostController::class => EmbedHostController::class],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->add('host_settings', '/host/{tenant}/settings/{section}')->controller([EmbedHostController::class, 'page'])->methods(['GET', 'POST']);
            },
            extensions: ['twig' => ['paths' => [dirname(__DIR__, 2) . '/Fixtures/templates' => 'Host']]],
        );

        $super   = $this->client($kernel, 'super');
        $crawler = $super->request('GET', '/host/acme/settings/general');
        self::assertSame(200, $super->getResponse()->getStatusCode());
        $html = (string) $super->getResponse()->getContent();
        self::assertStringContainsString('href="/host/acme/settings/brand"', $html, 'the embed allowlist chooses its tabs; hiding does not apply');
        $super->submit($crawler->filter('form[name="settings_section"]')->form(), ['settings_section[site_name]' => 'Hidden but embedded']);
        self::assertSame('/host/acme/settings/general', $super->getResponse()->headers->get('Location'));
        self::assertSame('Hidden but embedded', $this->settings($kernel)->get('general.site_name'));

        $admin = $this->client($kernel, 'admin');
        $admin->request('GET', '/host/acme/settings/brand');
        self::assertSame(403, $admin->getResponse()->getStatusCode(), 'still guarded by its area');
        $viewer = $this->client($kernel, 'viewer');
        $viewer->request('GET', '/host/acme/settings/general');
        self::assertSame(403, $viewer->getResponse()->getStatusCode(), 'still behind the UI gate');

        // The bundle page: general and brand hidden, so the redirect lands on mailer for super.
        $super->request('GET', '/admin/settings');
        self::assertSame('/admin/settings/sections/mailer', $super->getResponse()->headers->get('Location'));
    }
}
