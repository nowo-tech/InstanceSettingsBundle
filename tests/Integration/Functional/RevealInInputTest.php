<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\GroupedRevealFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\RevealFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Form;

use function array_replace_recursive;

/**
 * 1.17 web_ui.reveal_in_input: a stored revealable secret is LOCKED. The server ignores a value submitted for it unless
 * its Replace box is ticked (a read-only input edited in the browser changes nothing), the clear box still deletes it,
 * and every host without the option keeps the overwrite-by-typing behaviour.
 */
final class RevealInInputTest extends KernelTestCase
{
    private const SECRET = 'placeholder-client-secret-7f3a';

    private const IN_INPUT = ['web_ui' => ['reveal_on_toggle' => true, 'reveal_in_input' => true]];

    public function testALockedRowIgnoresATamperedValueAndReplaceUnlocksIt(): void
    {
        $kernel = $this->boot(self::IN_INPUT);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET, 'signing_key' => 'sign-1']);
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');
        $html    = (string) $client->getResponse()->getContent();

        $root = $crawler->filter('[data-nowo-instance-settings-reveal-root]');
        self::assertSame('true', $root->attr('data-in-input'));
        self::assertSame('Stored value shown in the field.', $root->attr('data-shown-label'));
        self::assertSame('Stored value hidden.', $root->attr('data-hidden-label'));
        self::assertStringContainsString('data-nowo-instance-settings-reveal-status', $root->filter('template')->html());
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-reveal="client_secret"][data-nowo-instance-settings-locked="true"]'));
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-replace-for="client_secret"] input[type="checkbox"][name="settings_section[client_secret:replace]"]'));
        self::assertStringContainsString('Replace the stored value', $html);
        self::assertCount(0, $crawler->filter('[name="settings_section[signing_key:replace]"]'), 'a write-only secret is not locked');
        self::assertStringNotContainsString(self::SECRET, $html);

        // A tampered read-only input (a value typed through the devtools): ignored, the stored secret is kept.
        $client->submit($this->form($client), ['settings_section[client_secret][value]' => 'tampered-value', 'settings_section[client_id]' => 'client-1']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertSame(self::SECRET, $this->settings($kernel)->getSecret('oauth.client_secret'));
        self::assertSame('client-1', $this->settings($kernel)->get('oauth.client_id'), 'the rest of the form is saved');

        // Replace ticked: the typed value replaces the secret.
        $form = $this->form($client);
        self::tick($form, 'settings_section[client_secret:replace]');
        $client->submit($form, ['settings_section[client_secret][value]' => 'replaced-value']);
        $this->settings($kernel)->reset();
        self::assertSame('replaced-value', $this->settings($kernel)->getSecret('oauth.client_secret'));

        // Replace ticked with an empty input keeps the secret ("blank keeps").
        $form = $this->form($client);
        self::tick($form, 'settings_section[client_secret:replace]');
        $client->submit($form);
        $this->settings($kernel)->reset();
        self::assertSame('replaced-value', $this->settings($kernel)->getSecret('oauth.client_secret'));

        // The clear box still deletes it; with no stored value the row is a normal input again.
        $form = $this->form($client);
        self::tick($form, 'settings_section[client_secret][clear]');
        $client->submit($form, ['settings_section[client_secret][value]' => 'ignored-with-clear']);
        $this->settings($kernel)->reset();
        self::assertNull($this->settings($kernel)->getSecret('oauth.client_secret'));
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-locked]'));
        self::assertCount(0, $crawler->filter('[name="settings_section[client_secret:replace]"]'));
        $client->submit($this->form($client), ['settings_section[client_secret][value]' => 'typed-when-empty']);
        $this->settings($kernel)->reset();
        self::assertSame('typed-when-empty', $this->settings($kernel)->getSecret('oauth.client_secret'));
    }

    public function testWithoutTheOptionTypingStillOverwrites(): void
    {
        foreach ([['web_ui' => ['reveal_on_toggle' => true]], ['web_ui' => ['reveal_in_input' => true]]] as $config) {
            $kernel = $this->boot($config);
            $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
            $client  = $this->client($kernel, 'developer');
            $crawler = $client->request('GET', '/admin/settings/sections/oauth');
            self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-locked]'));
            self::assertNull($crawler->filter('[data-nowo-instance-settings-reveal-root]')->attr('data-in-input'), 'reveal_in_input is ignored without reveal_on_toggle');

            $client->submit($this->form($client), ['settings_section[client_secret][value]' => 'overwritten']);
            $this->settings($kernel)->reset();
            self::assertSame('overwritten', $this->settings($kernel)->getSecret('oauth.client_secret'), 'byte-identical 1.16 behaviour');
        }
    }

    public function testAUserWhoMayNotRevealGetsNoLock(): void
    {
        $kernel = $this->boot(self::IN_INPUT);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client  = $this->client($kernel, 'admin');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-locked]'));
        self::assertCount(0, $crawler->filter('[name="settings_section[client_secret:replace]"]'));

        $client->submit($this->form($client), ['settings_section[client_secret][value]' => 'overwritten']);
        $this->settings($kernel)->reset();
        self::assertSame('overwritten', $this->settings($kernel)->getSecret('oauth.client_secret'));
    }

    public function testTheTabsLayoutKeepsTheReplaceBoxWithItsSecret(): void
    {
        $kernel = $this->boot(array_replace_recursive(self::IN_INPUT, ['web_ui' => ['section_layout' => 'tabs']]), GroupedRevealFixtureSection::class);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $crawler = $this->client($kernel, 'developer')->request('GET', '/admin/settings/sections/oauth');

        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-group-panel="keys"] [name="settings_section[client_secret:replace]"]'));
    }

    private function form(KernelBrowser $client): Form
    {
        return $client->request('GET', '/admin/settings/sections/oauth')->filter('form[name="settings_section"]')->form();
    }

    /**
     * @param array<string, mixed> $config
     * @param class-string $section
     */
    private function boot(array $config, string $section = RevealFixtureSection::class): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            array_replace_recursive(['security' => ['reveal_rate_limiter' => 'limiter.instance_settings_reveal']], $config),
            services: [$section => $section],
            extensions: ['framework' => ['rate_limiter' => ['instance_settings_reveal' => ['policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 hour']]]],
        );
    }
}
