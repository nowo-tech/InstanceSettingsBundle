<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Exception\InvalidSettingValueException;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ProviderFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;

/**
 * 1.3 provider-dependent fields through the admin UI: the page connects the `visible-when` peer, a save only
 * validates and writes the fields visible for the submitted provider, hidden fields keep their stored rows (plain and
 * secret), "empty = delete" applies to visible fields only.
 */
final class ProviderFieldsTest extends KernelTestCase
{
    public function testSwitchingProviderKeepsTheHiddenValues(): void
    {
        $kernel = $this->bootKernel(services: ['transport_section' => ProviderFixtureSection::class]);
        $client = $this->client($kernel);

        $crawler = $client->request('GET', '/admin/settings/sections/transport');
        $html    = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('data-controller="visible-when"', $html);
        self::assertStringContainsString('data-nowo-ui-visible-when-field="settings_section[provider]"', $html);
        self::assertStringContainsString('data-nowo-ui-visible-when-in="[&quot;smtp&quot;]"', $html);

        // smtp: host required and stored.
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), [
            'settings_section[provider]'  => 'smtp',
            'settings_section[smtp_host]' => 'mail.example.test',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler = $client->followRedirect();

        // api: smtp fields hidden — a blank / invalid hidden value is neither validated nor written.
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), [
            'settings_section[provider]'       => 'api',
            'settings_section[smtp_host]'      => '',
            'settings_section[smtp_port]'      => 'oops',
            'settings_section[api_region]'     => 'eu',
            'settings_section[api_key][value]' => 'REDACTED-API-KEY',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler  = $client->followRedirect();
        $settings = $this->settings($kernel);
        self::assertSame('api', $settings->get('transport.provider'));
        self::assertSame('mail.example.test', $settings->get('transport.smtp_host'), 'hidden for api: stored row kept');
        self::assertSame('eu', $settings->get('transport.api_region'));
        self::assertSame('REDACTED-API-KEY', $settings->getSecret('transport.api_key'));
        self::assertStringNotContainsString('REDACTED-API-KEY', (string) $client->getResponse()->getContent());

        // smtp again: api fields hidden; a blank region and a new key are ignored.
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), [
            'settings_section[provider]'       => 'smtp',
            'settings_section[api_region]'     => '',
            'settings_section[api_key][value]' => 'REDACTED-OTHER-KEY',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $crawler  = $client->followRedirect();
        $settings = $this->settings($kernel);
        self::assertSame('mail.example.test', $settings->get('transport.smtp_host'));
        self::assertSame('eu', $settings->get('transport.api_region'), 'hidden non-secret: empty does not delete');
        self::assertSame('REDACTED-API-KEY', $settings->getSecret('transport.api_key'), 'hidden secret: a value is never written');

        // An explicit "clear" on the hidden secret deletes it (leaked credential of an inactive provider).
        $form = $crawler->filter('form[name="settings_section"]')->form();
        self::tick($form, 'settings_section[api_key][clear]');
        $client->submit($form, ['settings_section[provider]' => 'smtp', 'settings_section[api_key][value]' => 'REDACTED-THIRD-KEY']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $client->followRedirect();
        $settings = $this->settings($kernel);
        self::assertFalse($settings->hasSecret('transport.api_key'), 'hidden secret cleared, never written');
        self::assertFalse($settings->isOverridden('transport.api_key'));
        self::assertSame('eu', $settings->get('transport.api_region'));

        // Visible fields: validation and "empty = delete" still apply.
        $crawler = $client->request('GET', '/admin/settings/sections/transport');
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), [
            'settings_section[provider]'  => 'smtp',
            'settings_section[smtp_host]' => '',
        ]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'required smtp_host is visible for smtp');
        self::assertSame('mail.example.test', $this->settings($kernel)->get('transport.smtp_host'));

        $crawler = $client->request('GET', '/admin/settings/sections/transport');
        $client->submit($crawler->filter('form[name="settings_section"]')->form(), [
            'settings_section[provider]'   => 'api',
            'settings_section[api_region]' => 'usa',
        ]);
        self::assertSame(422, $client->getResponse()->getStatusCode(), 'constraint of a visible field');

        $client->submit($crawler->filter('form[name="settings_section"]')->form(), [
            'settings_section[provider]'   => 'api',
            'settings_section[api_region]' => '',
        ]);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertFalse($this->settings($kernel)->isOverridden('transport.api_region'), 'visible + empty = delete');
        self::assertTrue($this->settings($kernel)->isOverridden('transport.smtp_host'));

        // API saves: `required` only applies to a provider-dependent field visible for the saved values.
        $settings = $this->settings($kernel);
        self::assertSame(['smtp_host'], $settings->save('transport', ['provider' => 'api', 'smtp_host' => null]), 'hidden for api: required not enforced');
        self::assertFalse($settings->isOverridden('transport.smtp_host'));
        $this->expectException(InvalidSettingValueException::class);
        $settings->save('transport', ['provider' => 'smtp', 'smtp_host' => null]);
    }

    public function testSectionsWithoutVisibleWhenRenderUnchanged(): void
    {
        $kernel = $this->bootKernel(services: ['transport_section' => ProviderFixtureSection::class]);
        $client = $this->client($kernel);
        $client->request('GET', '/admin/settings/sections/mailer');
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('visible-when', $html);
    }
}
