<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Embed\SettingsSectionEmbedder;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\EmbedHostController;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function file_put_contents;
use function tempnam;

/**
 * IS-1 (1.6): a host route embeds settings sections through {@see SettingsSectionEmbedder} with the same access
 * checks as the bundle route (UI gate, section area, CSRF), a nav allowlist that only narrows, the redirect to the
 * host route after a save, uploads through the image pipeline and the host template / layout / header.
 */
final class SectionEmbedTest extends KernelTestCase
{
    public function testTheAllowlistNarrowsTheNavAndASaveRedirectsToTheHostRoute(): void
    {
        $kernel = $this->embedKernel();
        $client = $this->client($kernel, 'super');

        $crawler = $client->request('GET', '/host/acme/settings/general');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-embedded="true"', $html);
        self::assertStringContainsString('href="/host/acme/settings/general"', $html);
        self::assertStringContainsString('href="/host/acme/settings/brand"', $html);
        self::assertStringNotContainsString('/admin/settings/sections/', $html, 'tabs link to the host routes only');
        self::assertStringNotContainsString('mailer', $html, 'a section outside the allowlist has no tab');
        self::assertStringNotContainsString('instance-settings-portability-link', $html, 'no import / export link in an embed');

        $form = $crawler->filter('form[name="settings_section"]')->form();
        self::assertSame('', (string) $crawler->filter('form[name="settings_section"]')->attr('action'), 'posts back to the host page');
        $client->submit($form, ['settings_section[site_name]' => 'Embedded']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/host/acme/settings/general', $client->getResponse()->headers->get('Location'));
        self::assertSame('Embedded', $this->settings($kernel)->get('general.site_name'));
        $client->followRedirect();
        self::assertStringContainsString('Settings saved.', (string) $client->getResponse()->getContent());

        // Outside the allowlist: 404, even for a user who may open it on the bundle route.
        $client->request('GET', '/host/acme/settings/mailer');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('GET', '/admin/settings/sections/mailer');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $client->request('GET', '/host/acme/settings/nope');
        self::assertSame(404, $client->getResponse()->getStatusCode());
    }

    public function testAccessDeniedStaysDeniedThroughTheEmbed(): void
    {
        $kernel = $this->embedKernel();

        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', '/host/acme/settings/general');
        self::assertSame(401, $anonymous->getResponse()->getStatusCode(), 'the UI gate runs although the host route is outside the prefix');

        // The UI gate runs before the allowlist / registry lookup (the bundle route's order): no probing of names.
        foreach (['mailer', 'nope'] as $section) {
            $anonymous->request('GET', '/host/acme/settings/' . $section);
            self::assertSame(401, $anonymous->getResponse()->getStatusCode(), $section);
        }

        $viewer = $this->client($kernel, 'viewer');
        foreach (['general', 'mailer', 'nope'] as $section) {
            $viewer->request('GET', '/host/acme/settings/' . $section);
            self::assertSame(403, $viewer->getResponse()->getStatusCode(), $section);
        }

        // brand needs ROLE_SUPER_ADMIN: allowlisted, but hidden and denied for admin.
        $admin   = $this->client($kernel, 'admin');
        $crawler = $admin->request('GET', '/host/acme/settings/general');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertStringNotContainsString('/host/acme/settings/brand', (string) $admin->getResponse()->getContent(), 'the allowlist never grants');
        $admin->request('GET', '/host/acme/settings/brand');
        self::assertSame(403, $admin->getResponse()->getStatusCode());

        // A POST to a denied section is refused before the form is read.
        $form = $crawler->filter('form[name="settings_section"]')->form();
        $admin->request('POST', '/host/acme/settings/brand', $form->getPhpValues());
        self::assertSame(403, $admin->getResponse()->getStatusCode());
    }

    public function testAnAreaRoleAloneNeverOpensTheUi(): void
    {
        // Audit JE-L1 / JE-I4: the viewer holds area_roles.general (ROLE_USER) but no access_roles role.
        $kernel = $this->embedKernel();
        $viewer = $this->client($kernel, 'viewer');

        $viewer->request('GET', '/host/bundle-page');
        self::assertSame(403, $viewer->getResponse()->getStatusCode(), 'handleBundlePage() runs the UI gate itself');
        $viewer->request('POST', '/host/bundle-page', ['settings_section' => ['site_name' => 'Viewer']]);
        self::assertSame(403, $viewer->getResponse()->getStatusCode());
        self::assertNotSame('Viewer', $this->settings($kernel)->get('general.site_name'));
        $viewer->request('GET', '/host/menu');
        self::assertSame('', $viewer->getResponse()->getContent(), 'accessibleSections() lists nothing');
        $viewer->request('GET', '/host/acme/settings/general');
        self::assertSame(403, $viewer->getResponse()->getStatusCode());

        $admin = $this->client($kernel, 'admin');
        $admin->request('GET', '/host/bundle-page');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertSame('general', $admin->getResponse()->headers->get('X-Menu'));
    }

    public function testCsrfIsCheckedOnTheEmbed(): void
    {
        $kernel  = $this->embedKernel();
        $client  = $this->client($kernel, 'super');
        $crawler = $client->request('GET', '/host/acme/settings/general');
        $form    = $crawler->filter('form[name="settings_section"]')->form();

        $client->submit($form, ['settings_section[_token]' => 'forged', 'settings_section[site_name]' => 'Forged']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertNotSame('Forged', $this->settings($kernel)->get('general.site_name'));
    }

    public function testTheRedirectIgnoresEveryRequestInput(): void
    {
        $kernel                 = $this->embedKernel();
        $client                 = $this->client($kernel, 'super');
        $crawler                = $client->request('GET', '/host/acme/settings/general');
        $values                 = $crawler->filter('form[name="settings_section"]')->form()->getPhpValues();
        $values['_target_path'] = 'https://evil.example/';
        $values['redirect']     = '//evil.example/';

        $client->request('POST', '/host/acme/settings/general?redirect=https://evil.example/&_target_path=//evil.example/', $values, [], ['HTTP_REFERER' => 'https://evil.example/', 'HTTP_HOST' => 'localhost']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/host/acme/settings/general', $client->getResponse()->headers->get('Location'), 'only the host route of the section');
    }

    public function testSecretsAreNeverRenderedBack(): void
    {
        $kernel  = $this->embedKernel();
        $client  = $this->client($kernel, 'super');
        $crawler = $client->request('GET', '/host/mailer');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->submit($crawler->filter('form[name="settings_section"]')->form(), ['settings_section[dsn][value]' => 'smtp://REDACTED-EMBED@localhost:25']);
        self::assertSame('/host/mailer', $client->getResponse()->headers->get('Location'));
        $crawler = $client->followRedirect();
        self::assertStringNotContainsString('REDACTED-EMBED', (string) $client->getResponse()->getContent(), 'a stored secret is never rendered back');
        self::assertSame('smtp://REDACTED-EMBED@localhost:25', $this->settings($kernel)->getSecret('mailer.dsn'));

        // Blank keeps it, and a failed save does not echo a submitted secret either.
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), ['settings_section[from]' => 'not-an-email', 'settings_section[dsn][value]' => 'smtp://REDACTED-SUBMITTED@localhost:25']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('REDACTED-', (string) $client->getResponse()->getContent());
        self::assertSame('smtp://REDACTED-EMBED@localhost:25', $this->settings($kernel)->getSecret('mailer.dsn'));
    }

    public function testUploadsGoThroughTheImagePipeline(): void
    {
        $kernel  = $this->embedKernel();
        $client  = $this->client($kernel, 'super');
        $crawler = $client->request('GET', '/host/acme/settings/brand');
        self::assertSame(200, $client->getResponse()->getStatusCode());

        $form = $crawler->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[logo][file]', $this->tempFile(ImageFactory::png(120, 40) . '<script>alert(1)</script>'));
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('/host/acme/settings/brand', $client->getResponse()->headers->get('Location'));
        $file = $this->settings($kernel)->getFile('brand.logo');
        self::assertNotNull($file);
        self::assertSame('image/png', $file->mimeType);

        // A refused upload (not an image) is a field error, nothing replaced.
        $crawler = $client->request('GET', '/host/acme/settings/brand');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[icon][file]', $this->tempFile('<svg xmlns="http://www.w3.org/2000/svg"/>'));
        $client->submit($form);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertNull($this->settings($kernel)->getFile('brand.icon'));
    }

    public function testHostTemplateLayoutHeaderAndContext(): void
    {
        $kernel  = $this->embedKernel();
        $client  = $this->client($kernel, 'super');
        $crawler = $client->request('GET', '/host/general');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-testid="host-layout"', $html);
        self::assertStringContainsString('from the host', $html);
        self::assertStringContainsString('Host appearance', $html);
        self::assertStringNotContainsString('never wins', $html, 'the bundle variables win');
        self::assertCount(0, $crawler->filter('[data-nowo-ui-region="tabs"]'), 'one section: no tabs');

        $client->submit($crawler->filter('form[name="settings_section"]')->form());
        self::assertSame('/host/general', $client->getResponse()->headers->get('Location'));
    }

    private function embedKernel(): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            [
                'files'    => ['enabled' => true],
                'security' => ['area_roles' => ['general' => ['ROLE_USER', 'ROLE_ADMIN'], 'mailer' => ['ROLE_SUPER_ADMIN'], 'brand' => ['ROLE_SUPER_ADMIN'], '_portability' => ['ROLE_SUPER_ADMIN']]],
            ],
            [ImageFixtureSection::class => ImageFixtureSection::class, EmbedHostController::class => EmbedHostController::class],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->add('host_settings', '/host/{tenant}/settings/{section}')->controller([EmbedHostController::class, 'page'])->methods(['GET', 'POST']);
                $routes->add('host_bundle_page', '/host/bundle-page')->controller([EmbedHostController::class, 'bundlePage'])->methods(['GET', 'POST']);
                $routes->add('host_menu', '/host/menu')->controller([EmbedHostController::class, 'menu'])->methods(['GET']);
                $routes->add('host_mailer', '/host/mailer')->controller([EmbedHostController::class, 'mailer'])->methods(['GET', 'POST']);
                $routes->add('host_general', '/host/general')->controller([EmbedHostController::class, 'general'])->methods(['GET', 'POST']);
            },
            extensions: ['twig' => ['paths' => [dirname(__DIR__, 2) . '/Fixtures/templates' => 'Host']]],
        );
    }

    private function tempFile(string $bytes): string
    {
        $path = (string) tempnam(InstanceSettingsTestKernel::baseDir(), 'up');
        file_put_contents($path, $bytes);

        return $path;
    }
}
