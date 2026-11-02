<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\DefaultProviderFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;

/**
 * 1.3.1 audit N4, admin path: an optional provider choice posted as the blank placeholder (its default `smtp` will
 * apply) plus "clear" on the required secret the browser hid is refused; the secret stays stored.
 */
final class EmptyProviderAdminTest extends KernelTestCase
{
    public function testBlankPlaceholderCannotClearTheDefaultProvidersRequiredSecret(): void
    {
        $kernel   = $this->bootKernel(services: ['relay_section' => DefaultProviderFixtureSection::class]);
        $settings = $this->settings($kernel);
        $settings->save('relay', ['provider' => 'smtp', 'key' => 'REDACTED-RELAY-KEY', 'host' => 'relay.example.test', 'tracking_domain' => 't.example.test']);

        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/sections/relay');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::tick($form, 'settings_section[key][clear]');
        $client->submit($form, ['settings_section[provider]' => '']);

        self::assertSame(422, $client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('REDACTED-RELAY-KEY', (string) $client->getResponse()->getContent());
        self::assertSame('REDACTED-RELAY-KEY', $this->settings($kernel)->getSecret('relay.key'));
    }
}
