<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\SwitchedSecretFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

use function array_replace_recursive;

/**
 * 1.18.1 (Beacon report): with `web_ui.reveal_in_input`, a typed value posted without the Replace box for a locked
 * secret row answers the same whether the row is visible or hidden by its `visible_when` switch — 422, the error
 * "Tick “Replace the stored value” to change it." on that row, nothing saved — in both `hidden_secrets` modes. Before,
 * a hidden row answered 302 (the value was dropped, the secret kept). The `keep_typed` residual of audit L1 (delta 1)
 * is the same path.
 */
final class LockedHiddenSecretTest extends KernelTestCase
{
    private const SECRET = 'placeholder-signing-secret';

    private const ERROR = 'Tick “Replace the stored value” to change it.';

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function modes(): iterable
    {
        yield 'drop' => ['drop'];
        yield 'keep_typed' => ['keep_typed'];
    }

    #[DataProvider('modes')]
    public function testATypedValueWithoutReplaceIsRefusedOnAVisibleAndOnAHiddenRow(string $mode): void
    {
        $kernel = $this->boot($mode);
        $this->settings($kernel)->save('webhooks', ['enabled' => true, 'signing_secret' => self::SECRET]);
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/settings/sections/webhooks');
        self::assertCount(1, $crawler->filter('[data-nowo-instance-settings-locked="signing_secret"]'));

        // Visible row (switch on).
        $client->submit($this->form($client), ['settings_section[signing_secret][value]' => 'typed-visible', 'settings_section[label]' => 'changed']);
        $visible = $this->assertRefused($client, $kernel);

        // Hidden row (switch off in the same POST): the same answer, plus the notice naming the hidden row.
        $form = $this->switchedOff($client);
        $client->submit($form, ['settings_section[signing_secret][value]' => 'typed-hidden', 'settings_section[label]' => 'changed']);
        $hidden = $this->assertRefused($client, $kernel);
        // Audit QC-I4: a notice of its own, which says how to change a hidden stored secret.
        self::assertStringContainsString('The settings were not saved. A value was typed for “webhooks.signing_secret”, whose row is hidden and holds a stored secret: show the row, then tick “Replace the stored value” to change it, or leave it blank.', $hidden, 'the hidden row\'s refusal is named above the form');
        self::assertStringNotContainsString('is not valid', $hidden);
        self::assertStringNotContainsString('The settings were not saved. A value was typed for', $visible);
        self::assertTrue($this->settings($kernel)->get('webhooks.enabled'), 'the switch is not saved either (all or nothing)');
    }

    #[DataProvider('modes')]
    public function testAHiddenLockedRowStillSavesUntouchedClearsAndFollowsItsModeWithReplace(string $mode): void
    {
        $kernel = $this->boot($mode);
        $this->settings($kernel)->save('webhooks', ['enabled' => true, 'signing_secret' => self::SECRET]);
        $client = $this->client($kernel);

        // Untouched hidden row: the switch is saved, the secret kept.
        $form = $this->switchedOff($client);
        $client->submit($form);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertFalse($this->settings($kernel)->get('webhooks.enabled'));
        self::assertSame(self::SECRET, $this->settings($kernel)->getSecret('webhooks.signing_secret'));

        // Replace ticked on the hidden row: drop ignores the value, keep_typed saves it (the 1.9 rules).
        $form = $this->form($client);
        self::tick($form, 'settings_section[signing_secret:replace]');
        $client->submit($form, ['settings_section[signing_secret][value]' => 'replaced-hidden']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertSame($mode === 'keep_typed' ? 'replaced-hidden' : self::SECRET, $this->settings($kernel)->getSecret('webhooks.signing_secret'));

        // The clear box deletes the hidden secret, whatever was typed.
        $form = $this->form($client);
        self::tick($form, 'settings_section[signing_secret][clear]');
        $client->submit($form, ['settings_section[signing_secret][value]' => 'ignored-with-clear']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertNull($this->settings($kernel)->getSecret('webhooks.signing_secret'));
    }

    public function testWithoutTheOptionAHiddenRowKeepsThe19Rules(): void
    {
        $kernel = $this->bootKernel(services: ['webhooks_section' => SwitchedSecretFixtureSection::class]);
        $this->settings($kernel)->save('webhooks', ['enabled' => true, 'signing_secret' => self::SECRET]);
        $client = $this->client($kernel);

        $form = $this->switchedOff($client);
        $client->submit($form, ['settings_section[signing_secret][value]' => 'typed-hidden']);
        self::assertSame(302, $client->getResponse()->getStatusCode(), 'drop: a hidden secret is not saved and not refused');
        $this->settings($kernel)->reset();
        self::assertSame(self::SECRET, $this->settings($kernel)->getSecret('webhooks.signing_secret'));
    }

    private function assertRefused(KernelBrowser $client, InstanceSettingsTestKernel $kernel): string
    {
        self::assertSame(422, $client->getResponse()->getStatusCode());
        $page = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression('#<li id="settings_section_signing_secret_error1">' . self::ERROR . '</li>#', $page, 'the error is on the secret row');
        self::assertStringNotContainsString('Settings saved.', $page);
        self::assertStringNotContainsString('typed-', $page);
        $this->settings($kernel)->reset();
        self::assertSame(self::SECRET, $this->settings($kernel)->getSecret('webhooks.signing_secret'));
        self::assertNull($this->settings($kernel)->get('webhooks.label'), 'nothing is saved');

        return $page;
    }

    /**
     * The form with the `enabled` switch unticked (the secret row is hidden for the submitted values).
     */
    private function switchedOff(KernelBrowser $client): Form
    {
        $form   = $this->form($client);
        $switch = $form['settings_section[enabled]'];
        self::assertInstanceOf(ChoiceFormField::class, $switch);
        $switch->untick();

        return $form;
    }

    private function form(KernelBrowser $client): Form
    {
        return $client->request('GET', '/admin/settings/sections/webhooks')->filter('form[name="settings_section"]')->form();
    }

    private function boot(string $mode): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            array_replace_recursive(['security' => ['reveal_rate_limiter' => 'limiter.instance_settings_reveal']], ['web_ui' => ['reveal_on_toggle' => true, 'reveal_in_input' => true, 'hidden_secrets' => $mode]]),
            services: ['webhooks_section' => SwitchedSecretFixtureSection::class],
            extensions: ['framework' => ['rate_limiter' => ['instance_settings_reveal' => ['policy' => 'fixed_window', 'limit' => 100, 'interval' => '1 hour']]]],
        );
    }
}
