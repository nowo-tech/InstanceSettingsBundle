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
 * 1.17 web_ui.reveal_in_input: a stored revealable secret is LOCKED for every editor (owner decision), whether or not
 * the editor may reveal it. The server ignores a value submitted for it unless its Replace box is ticked (a read-only
 * input edited in the browser changes nothing), the clear box still deletes it, and every host without the option
 * keeps the overwrite-by-typing behaviour.
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
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-reveal="client_secret"][data-nowo-instance-settings-locked="client_secret"]'));
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-replace-for="client_secret"] input[type="checkbox"][name="settings_section[client_secret:replace]"]'));
        self::assertStringContainsString('Replace the stored value', $html);
        // Owner decision D-I3: a stored write-only (not revealable) secret is locked too, without a reveal control.
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-locked="signing_key"]'));
        self::assertCount(1, $crawler->filter('[name="settings_section[signing_key:replace]"]'));
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-reveal="signing_key"]'));
        self::assertStringNotContainsString(self::SECRET, $html);

        // A value typed into the locked row without Replace (no JavaScript, stale assets, a tampered input) is refused
        // with an error on that row (audit L1): never dropped silently under "Settings saved.", and nothing is saved.
        $client->submit($this->form($client), ['settings_section[client_secret][value]' => 'tampered-value', 'settings_section[client_id]' => 'client-1']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $page = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Tick “Replace the stored value” to change it.', $page);
        self::assertMatchesRegularExpression('#<li id="settings_section_client_secret_error1">Tick “Replace the stored value” to change it\.</li>#', $page, 'the error is on the secret row');
        self::assertStringNotContainsString('Settings saved.', $page);
        self::assertStringNotContainsString('tampered-value', $page);
        $this->settings($kernel)->reset();
        self::assertSame(self::SECRET, $this->settings($kernel)->getSecret('oauth.client_secret'));
        self::assertNull($this->settings($kernel)->get('oauth.client_id'), 'all-or-nothing: the rest of the form is not saved either');
        $client->submit($this->form($client), ['settings_section[client_id]' => 'client-1']);
        self::assertSame(302, $client->getResponse()->getStatusCode(), 'an untouched locked row saves the rest');

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
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-locked="client_secret"]'));
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-locked="signing_key"]'), 'the other stored secret stays locked (D-I3)');
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

    public function testAnEditorWhoMayNotRevealGetsTheSameLock(): void
    {
        $kernel = $this->boot(self::IN_INPUT);
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client  = $this->client($kernel, 'admin');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');

        // Same locked row and Replace box, no reveal control, the script loaded alone.
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-locked="client_secret"]'));
        self::assertCount(1, $crawler->filter('[name="settings_section[client_secret:replace]"]'));
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-reveal-root]'));
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-reveal]'));
        self::assertCount(1, $crawler->filter('script[data-nowo-instance-settings-reveal-script]'));
        self::assertStringNotContainsString(self::SECRET, (string) $client->getResponse()->getContent());

        // A value posted without Replace is refused for this editor too.
        $client->submit($this->form($client), ['settings_section[client_secret][value]' => 'overwritten']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertSame(self::SECRET, $this->settings($kernel)->getSecret('oauth.client_secret'));

        // With Replace ticked it is saved.
        $form = $this->form($client);
        self::tick($form, 'settings_section[client_secret:replace]');
        $client->submit($form, ['settings_section[client_secret][value]' => 'replaced-by-admin']);
        $this->settings($kernel)->reset();
        self::assertSame('replaced-by-admin', $this->settings($kernel)->getSecret('oauth.client_secret'));

        // A section without a stored revealable secret loads no lock script.
        $this->settings($kernel)->save('oauth', ['client_secret' => null]);
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');
        self::assertCount(0, $crawler->filter('script[data-nowo-instance-settings-reveal-script]'));
    }

    public function testAStoredNonRevealableSecretIsLockedForEveryEditor(): void
    {
        // Owner decision D-I3: every stored secret field is locked while the option is on, revealable or not.
        $kernel = $this->boot(self::IN_INPUT);
        $this->settings($kernel)->save('oauth', ['signing_key' => 'sign-1']);
        foreach (['developer', 'admin'] as $user) {
            $client  = $this->client($kernel, $user);
            $crawler = $client->request('GET', '/admin/settings/sections/oauth');
            self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-locked="signing_key"]'), $user);
            self::assertCount(1, $crawler->filter('[name="settings_section[signing_key:replace]"]'), $user);
            self::assertCount(1, $crawler->filter('script[data-nowo-instance-settings-reveal-script]'), $user);

            $client->submit($this->form($client), ['settings_section[signing_key][value]' => 'tampered']);
            self::assertSame(422, $client->getResponse()->getStatusCode(), $user);
            self::assertStringContainsString('Tick “Replace the stored value” to change it.', (string) $client->getResponse()->getContent());
            $this->settings($kernel)->reset();
            self::assertSame('sign-1', $this->settings($kernel)->getSecret('oauth.signing_key'), $user);
        }

        $form = $this->form($client);
        self::tick($form, 'settings_section[signing_key:replace]');
        $client->submit($form, ['settings_section[signing_key][value]' => 'sign-2']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertSame('sign-2', $this->settings($kernel)->getSecret('oauth.signing_key'));

        // Without the option the same field is overwritten by typing, as in 1.16.
        $plain = $this->boot(['web_ui' => ['reveal_on_toggle' => true]]);
        $this->settings($plain)->save('oauth', ['signing_key' => 'sign-1']);
        $client = $this->client($plain, 'admin');
        $client->submit($this->form($client), ['settings_section[signing_key][value]' => 'sign-typed']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->settings($plain)->reset();
        self::assertSame('sign-typed', $this->settings($plain)->getSecret('oauth.signing_key'));
    }

    public function testTheKillSwitchStillLocksTheRow(): void
    {
        $kernel = $this->boot(array_replace_recursive(self::IN_INPUT, ['security' => ['reveal_roles' => []]]));
        $this->settings($kernel)->save('oauth', ['client_secret' => self::SECRET]);
        $client  = $this->client($kernel, 'developer');
        $crawler = $client->request('GET', '/admin/settings/sections/oauth');
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-locked="client_secret"]'), 'nobody may reveal: the lock stays');
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-reveal-root]'));

        $client->submit($this->form($client), ['settings_section[client_secret][value]' => 'overwritten']);
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertSame(self::SECRET, $this->settings($kernel)->getSecret('oauth.client_secret'));
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
