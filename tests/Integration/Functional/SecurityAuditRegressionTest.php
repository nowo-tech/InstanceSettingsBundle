<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use const JSON_THROW_ON_ERROR;

/**
 * Regression tests of the REQ-SEC-004 audit (Appendix BY): M1 (portability vs area roles), L1 (secrets ending with
 * DoctrineEncrypt's marker), I3 (`security: false` firewall).
 */
final class SecurityAuditRegressionTest extends KernelTestCase
{
    private const AREAS = ['security' => ['area_roles' => ['mailer' => ['ROLE_SUPER_ADMIN'], '_portability' => ['ROLE_ADMIN']]]];

    public function testWebExportOnlyContainsTheSectionsTheUserMayOpen(): void
    {
        $kernel = $this->bootKernel(self::AREAS);
        $this->settings($kernel)->save('mailer', ['from' => 'ops@example.test', 'dsn' => 'smtp://user:REDACTED-TEST@smtp.example.test:25']);

        $admin = $this->client($kernel, 'admin');
        $admin->request('GET', '/admin/settings/sections/mailer');
        self::assertSame(403, $admin->getResponse()->getStatusCode());

        $crawler = $admin->request('GET', '/admin/settings/portability');
        $admin->submit($crawler->filter('form[name="portability_export"]')->form(), ['portability_export[include_secrets]' => '1']);
        $export = json_decode((string) $admin->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['general'], array_keys($export['settings']));
        self::assertSame([], $export['secrets']['values']);
        self::assertArrayHasKey('appearance', $export['providers']);

        $super   = $this->client($kernel, 'super');
        $crawler = $super->request('GET', '/admin/settings/portability');
        $super->submit($crawler->filter('form[name="portability_export"]')->form(), ['portability_export[include_secrets]' => '1']);
        $full = json_decode((string) $super->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['mailer', 'general'], array_keys($full['settings']));
        self::assertArrayHasKey('dsn', $full['secrets']['values']['mailer']);
    }

    public function testWebImportTouchingAForbiddenSectionChangesNothing(): void
    {
        $kernel = $this->bootKernel(self::AREAS);
        $this->settings($kernel)->save('mailer', ['from' => 'ops@example.test']);
        $file = tempnam(sys_get_temp_dir(), 'nis') ?: '';
        file_put_contents($file, json_encode([
            'schema'   => 'nowo-instance-settings',
            'version'  => 1,
            'settings' => ['general' => ['page_size' => 33], 'mailer' => ['from' => 'attacker@evil.test']],
        ], JSON_THROW_ON_ERROR));

        $admin   = $this->client($kernel, 'admin');
        $crawler = $admin->request('GET', '/admin/settings/portability');
        $form    = $crawler->filter('form[name="portability_import"]')->form();
        self::upload($form, 'portability_import[file]', $file);
        $admin->submit($form);
        $admin->followRedirect();
        unlink($file);

        self::assertStringContainsString('You may not change this section: mailer.', (string) $admin->getResponse()->getContent());
        self::assertSame('ops@example.test', $this->settings($kernel)->get('mailer.from'));
        self::assertSame(20, $this->settings($kernel)->getInt('general.page_size'), 'the whole import was refused');
    }

    public function testAreaRolesWithoutAnExplicitPortabilityAreaAreRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('has no "_portability" entry');
        $this->bootKernel(['security' => ['area_roles' => ['mailer' => ['ROLE_SUPER_ADMIN']]]]);
    }

    public function testAreaRolesWithoutPortabilityPageBoot(): void
    {
        $kernel = $this->bootKernel(['security' => ['area_roles' => ['mailer' => ['ROLE_SUPER_ADMIN']]], 'portability' => ['web_ui' => false]]);
        self::assertSame('noreply@example.test', $this->settings($kernel)->get('mailer.from'));
    }

    public function testSecretEndingWithTheEncryptionMarkerIsEncryptedAndKept(): void
    {
        $kernel = $this->bootKernel();
        $secret = 'smtp://u:REDACTED-TEST@h:25<ENC>';
        $this->settings($kernel)->save('mailer', ['dsn' => $secret]);

        $raw = (string) $this->connection($kernel)->fetchOne("SELECT secret_value FROM instance_setting_value WHERE name = 'dsn'");
        self::assertStringNotContainsString('REDACTED-TEST', $raw, 'encrypted at rest');
        self::assertStringEndsWith('<ENC>', $raw);

        $this->settings($kernel)->reset();
        self::assertSame($secret, $this->settings($kernel)->getSecret('mailer.dsn'));

        // I7: "0" is stored encrypted and read back.
        $this->settings($kernel)->save('mailer', ['dsn' => '0']);
        $this->settings($kernel)->reset();
        self::assertSame('0', $this->settings($kernel)->getSecret('mailer.dsn'));
        self::assertStringEndsWith('<ENC>', (string) $this->connection($kernel)->fetchOne("SELECT secret_value FROM instance_setting_value WHERE name = 'dsn'"));
    }

    public function testSecurityDisabledFirewallGetsA403(): void
    {
        $kernel = $this->bootKernel(without: ['security_off']);
        $client = $this->client($kernel, null);
        $client->request('GET', '/admin/settings/sections/general');
        self::assertSame(403, $client->getResponse()->getStatusCode());
    }
}
