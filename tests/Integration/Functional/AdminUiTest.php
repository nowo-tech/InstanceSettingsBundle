<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\BrokenExportProvider;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;

use const JSON_THROW_ON_ERROR;

/**
 * The admin UI end to end: access (anonymous / no role / admin / area roles), section save with CSRF, secrets
 * (never rendered, kept when blank, cleared on request, encrypted at rest), import / export.
 */
final class AdminUiTest extends KernelTestCase
{
    public function testAccess(): void
    {
        $kernel = $this->bootKernel(['security' => ['area_roles' => ['mailer' => ['ROLE_SUPER_ADMIN'], '_portability' => ['ROLE_SUPER_ADMIN']]]]);

        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', '/admin/settings');
        self::assertSame(401, $anonymous->getResponse()->getStatusCode());

        $viewer = $this->client($kernel, 'viewer');
        $viewer->request('GET', '/admin/settings/sections/general');
        self::assertSame(403, $viewer->getResponse()->getStatusCode());

        $admin = $this->client($kernel, 'admin');
        $admin->request('GET', '/admin/settings');
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertSame('/admin/settings/sections/general', $admin->getResponse()->headers->get('Location'), 'mailer needs ROLE_SUPER_ADMIN');

        $admin->request('GET', '/admin/settings/sections/mailer');
        self::assertSame(403, $admin->getResponse()->getStatusCode());
        $admin->request('GET', '/admin/settings/sections/nope');
        self::assertSame(404, $admin->getResponse()->getStatusCode());

        $super = $this->client($kernel, 'super');
        $super->request('GET', '/admin/settings');
        self::assertSame('/admin/settings/sections/mailer', $super->getResponse()->headers->get('Location'));
    }

    public function testSectionSaveSecretsAndCsrf(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-testid="instance-settings-section"', (string) $client->getResponse()->getContent());
        self::assertStringContainsString('Sender address', (string) $client->getResponse()->getContent());

        $form = $crawler->filter('form[name="settings_section"]')->form();
        $client->submit($form, [
            'settings_section[from]'       => 'ops@example.test',
            'settings_section[dsn][value]' => 'smtp://REDACTED-TEST@localhost:25',
            'settings_section[enabled]'    => '1',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler = $client->followRedirect();
        $html    = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Settings saved.', $html);
        self::assertStringNotContainsString('REDACTED-TEST', $html, 'a stored secret is never rendered back');

        $settings = $this->settings($kernel);
        self::assertSame('ops@example.test', $settings->get('mailer.from'));
        self::assertSame('smtp://REDACTED-TEST@localhost:25', $settings->getSecret('mailer.dsn'));
        self::assertTrue($settings->getBool('mailer.enabled'));

        $connection = $this->connection($kernel);
        $raw        = (string) $connection->fetchOne("SELECT secret_value FROM instance_setting_value WHERE name = 'dsn'");
        self::assertStringNotContainsString('REDACTED-TEST', $raw, 'encrypted at rest');
        self::assertStringEndsWith('<ENC>', $raw);

        // Blank secret keeps it; "clear" removes it.
        $form = $crawler->filter('form[name="settings_section"]')->form();
        $client->submit($form, ['settings_section[from_name]' => 'Ops']);
        $client->followRedirect();
        $settings = $this->settings($kernel);
        self::assertSame('smtp://REDACTED-TEST@localhost:25', $settings->getSecret('mailer.dsn'));
        self::assertSame('Ops', $settings->get('mailer.from_name'));

        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::tick($form, 'settings_section[dsn][clear]');
        $client->submit($form);
        $client->followRedirect();
        self::assertNull($this->settings($kernel)->getSecret('mailer.dsn'));

        // Unchanged submit.
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        $client->submit($crawler->filter('form[name="settings_section"]')->form());
        $client->followRedirect();
        self::assertStringContainsString('Nothing changed.', (string) $client->getResponse()->getContent());

        // Invalid CSRF token: 422, nothing saved.
        $crawler = $client->request('GET', '/admin/settings/sections/mailer');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        $client->submit($form, ['settings_section[_token]' => 'forged', 'settings_section[from]' => 'evil@example.test']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertSame('ops@example.test', $this->settings($kernel)->get('mailer.from'));
    }

    public function testSectionValidationErrorsAreShownOnTheFields(): void
    {
        $kernel  = $this->bootKernel();
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/sections/general');
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), [
            'settings_section[page_size]' => '500',
            'settings_section[homepage]'  => 'ftp://example.test',
        ]);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('This value should be between 1 and 100.', $html);
        self::assertSame(20, $this->settings($kernel)->getInt('general.page_size'));
    }

    public function testPortabilityExportAndImport(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $this->settings($kernel)->save('mailer', ['from' => 'ops@example.test', 'dsn' => 'smtp://REDACTED-TEST']);

        $crawler = $client->request('GET', '/admin/settings/portability');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-testid="instance-settings-portability"', (string) $client->getResponse()->getContent());

        $client->submit($crawler->filter('form[name="portability_export"]')->form(), ['portability_export[include_secrets]' => '1']);
        $response = $client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('attachment; filename=nowo-instance-settings-sealed-', (string) $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $export = (string) $response->getContent();
        self::assertStringContainsString('enc:v2:', $export);
        self::assertStringNotContainsString('REDACTED', $export);

        // Import a changed copy: dry run first, then apply.
        $payload                                     = json_decode($export, true, 512, JSON_THROW_ON_ERROR);
        $payload['settings']['general']['page_size'] = 33;
        $file                                        = tempnam(sys_get_temp_dir(), 'nis') ?: '';
        file_put_contents($file, json_encode($payload, JSON_THROW_ON_ERROR));

        $crawler = $client->request('GET', '/admin/settings/portability');
        $form    = $crawler->filter('form[name="portability_import"]')->form();
        self::upload($form, 'portability_import[file]', $file);
        self::tick($form, 'portability_import[dry_run]');
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode(), (string) $client->getResponse()->getContent());
        $client->followRedirect();
        self::assertStringContainsString('would be applied', (string) $client->getResponse()->getContent());
        self::assertSame(20, $this->settings($kernel)->getInt('general.page_size'));

        $crawler = $client->request('GET', '/admin/settings/portability');
        $form    = $crawler->filter('form[name="portability_import"]')->form();
        self::upload($form, 'portability_import[file]', $file);
        $client->submit($form);
        $client->followRedirect();
        self::assertStringContainsString('Configuration imported', (string) $client->getResponse()->getContent());
        self::assertSame(33, $this->settings($kernel)->getInt('general.page_size'));

        // A refused file names the key, never the value.
        file_put_contents($file, json_encode(['schema' => 'nowo-instance-settings', 'version' => 1, 'settings' => ['general' => ['page_size' => 1000]]], JSON_THROW_ON_ERROR));
        $crawler = $client->request('GET', '/admin/settings/portability');
        $form    = $crawler->filter('form[name="portability_import"]')->form();
        self::upload($form, 'portability_import[file]', $file);
        $client->submit($form);
        $client->followRedirect();
        self::assertStringContainsString('Invalid value for general.page_size.', (string) $client->getResponse()->getContent());

        // No file: 422 with the form error.
        $crawler = $client->request('GET', '/admin/settings/portability');
        $client->submit($crawler->filter('form[name="portability_import"]')->form());
        self::assertSame(422, $client->getResponse()->getStatusCode());

        // Forged export token.
        $client->request('POST', '/admin/settings/portability/export', ['portability_export' => ['_token' => 'forged']]);
        self::assertSame(403, $client->getResponse()->getStatusCode());
        unlink($file);
    }

    public function testExportWithoutSecretsAndDisabledPortability(): void
    {
        $kernel  = $this->bootKernel(['portability' => ['sealed_secrets' => false]]);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/portability');
        self::assertCount(0, $crawler->filter('input[name="portability_export[include_secrets]"]'));
        $client->submit($crawler->filter('form[name="portability_export"]')->form());
        self::assertStringNotContainsString('"secrets"', (string) $client->getResponse()->getContent());

        $disabled = $this->bootKernel(['portability' => ['web_ui' => false]]);
        $client   = $this->client($disabled);
        $client->request('GET', '/admin/settings/portability');
        self::assertSame(404, $client->getResponse()->getStatusCode());
        $client->request('GET', '/admin/settings/sections/general');
        self::assertStringNotContainsString('instance-settings-portability-link', (string) $client->getResponse()->getContent());
    }

    public function testPortabilityAreaRolesAndEmptyIndex(): void
    {
        $kernel = $this->bootKernel(['security' => ['area_roles' => ['_portability' => ['ROLE_SUPER_ADMIN']]]], fixtures: false);
        $client = $this->client($kernel);

        $client->request('GET', '/admin/settings');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('data-testid="instance-settings-empty"', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString('instance-settings-portability-link', (string) $client->getResponse()->getContent());

        $client->request('GET', '/admin/settings/portability');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }

    public function testExportFailureIsReported(): void
    {
        $kernel  = $this->bootKernel(services: ['broken' => BrokenExportProvider::class]);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/portability');
        $client->submit($crawler->filter('form[name="portability_export"]')->form());
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $client->followRedirect();
        self::assertStringContainsString('The export failed.', (string) $client->getResponse()->getContent());
    }
}
