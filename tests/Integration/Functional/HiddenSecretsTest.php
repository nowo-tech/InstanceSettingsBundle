<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\KeepTypedProviderFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ProviderFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * 1.9 (Beacon spec 222, owner Q4 = b): `web_ui.hidden_secrets: keep_typed` saves a secret typed into a row hidden for
 * the submitted provider; `web_ui.hidden_secrets_notice` says, while they are dropped, that such secrets are not saved.
 */
final class HiddenSecretsTest extends KernelTestCase
{
    private const NOTICE = 'Secrets typed for a provider that is not selected are not saved';

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: class-string}>
     */
    public static function keepTypedSetups(): iterable
    {
        yield 'bundle-wide' => [['web_ui' => ['hidden_secrets' => 'keep_typed']], ProviderFixtureSection::class];
        yield 'per section' => [[], KeepTypedProviderFixtureSection::class];
    }

    /**
     * @param array<string, mixed> $config
     * @param class-string $section
     */
    #[DataProvider('keepTypedSetups')]
    public function testKeepTypedSavesASecretTypedForAnotherProvider(array $config, string $section): void
    {
        $kernel = $this->bootKernel(config: $config, services: ['transport_section' => $section]);
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/admin/settings/sections/transport');
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), [
            'settings_section[provider]'       => 'smtp',
            'settings_section[smtp_host]'      => 'mail.example.test',
            'settings_section[api_region]'     => 'typed',
            'settings_section[api_key][value]' => 'REDACTED-API-KEY',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler  = $client->followRedirect();
        $settings = $this->settings($kernel);
        self::assertSame('REDACTED-API-KEY', $settings->getSecret('transport.api_key'), 'hidden secret typed: saved');
        self::assertFalse($settings->isOverridden('transport.api_region'), 'hidden plain field: still neither validated nor written');
        self::assertStringNotContainsString('REDACTED-API-KEY', (string) $client->getResponse()->getContent());
        self::assertStringNotContainsString(self::NOTICE, (string) $client->getResponse()->getContent());

        // Blank keeps, "clear" still deletes.
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), ['settings_section[provider]' => 'smtp']);
        $crawler = $client->followRedirect();
        self::assertSame('REDACTED-API-KEY', $this->settings($kernel)->getSecret('transport.api_key'));
        $form = $crawler->filter('form[name="settings_section"]')->form();
        self::tick($form, 'settings_section[api_key][clear]');
        $client->submit($form, ['settings_section[provider]' => 'smtp']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertFalse($this->settings($kernel)->hasSecret('transport.api_key'));
    }

    public function testDropIsTheDefaultAndTheNoticeIsOptIn(): void
    {
        $kernel  = $this->bootKernel(services: ['transport_section' => ProviderFixtureSection::class]);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/sections/transport');
        self::assertStringNotContainsString(self::NOTICE, (string) $client->getResponse()->getContent());
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), [
            'settings_section[provider]'       => 'smtp',
            'settings_section[smtp_host]'      => 'mail.example.test',
            'settings_section[api_key][value]' => 'REDACTED-API-KEY',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertFalse($this->settings($kernel)->hasSecret('transport.api_key'), 'drop: never written');
    }

    public function testNoticeWhileDroppedOnSectionsWithProviderSecretsOnly(): void
    {
        $kernel = $this->bootKernel(config: ['web_ui' => ['hidden_secrets_notice' => true]], services: ['transport_section' => ProviderFixtureSection::class]);
        $client = $this->client($kernel);
        $client->request('GET', '/admin/settings/sections/transport');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(self::NOTICE, $html);
        self::assertStringContainsString('data-testid="instance-settings-notice"', $html);

        $client->request('GET', '/admin/settings/sections/mailer');
        self::assertStringNotContainsString(self::NOTICE, (string) $client->getResponse()->getContent(), 'no provider-dependent secret');

        $kernel = $this->bootKernel(config: ['web_ui' => ['hidden_secrets_notice' => true]], services: ['transport_section' => KeepTypedProviderFixtureSection::class]);
        $client = $this->client($kernel);
        $client->request('GET', '/admin/settings/sections/transport');
        self::assertStringNotContainsString(self::NOTICE, (string) $client->getResponse()->getContent(), 'keep_typed: nothing to warn about');
    }
}
