<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\ColorDefaultFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * Audit LM-M1 / LM-L2 / LM-I4 (1.12): the form shows what is stored (an unstored colour empty, never its default) and an
 * untouched re-submit never changes a stored colour; each reset button names its field; the assets carry the nonce.
 */
final class ColorFormDataTest extends KernelTestCase
{
    public function testNothingStoredShowsEmptyAndAnUntouchedSaveStoresNothing(): void
    {
        [$kernel, $admin] = $this->admin();

        self::assertSame('', $this->hexValue($admin));
        self::assertSame('#1d4ed8', $admin->getCrawler()->filter('#settings_section_tone_swatch')->attr('value'), 'the swatch shows the preset of the source');
        $this->resubmit($admin);
        self::assertFalse($this->settings($kernel)->isOverridden('tones.tone'));
        self::assertSame('#2563eb', $this->settings($kernel)->getString('tones.tone'), 'get() still gives the default');
    }

    public function testAStoredColourEqualToTheDefaultIsShownAndKept(): void
    {
        [$kernel, $admin] = $this->admin();
        $this->settings($kernel)->save('tones', ['tone' => '#2563EB']);
        self::assertTrue($this->settings($kernel)->isOverridden('tones.tone'), 'the default is not the preset (#1d4ed8): a custom colour');

        self::assertSame('#2563eb', $this->hexValue($admin));
        $this->resubmit($admin);
        self::assertTrue($this->settings($kernel)->isOverridden('tones.tone'), 'an untouched save keeps it (LM-M1)');
        self::assertSame('#2563eb', $this->settings($kernel)->getString('tones.tone'));
    }

    public function testAStoredColourThatBecameThePresetIsShownAndKeptUntilChanged(): void
    {
        [$kernel, $admin] = $this->admin();
        $settings         = $this->settings($kernel);
        $settings->save('tones', ['tone' => '#93c5fd']);
        $settings->save('tones', ['scheme' => 'dark']);
        self::assertTrue($settings->isOverridden('tones.tone'));

        self::assertSame('#93c5fd', $this->hexValue($admin));
        $this->resubmit($admin);
        self::assertSame('#93c5fd', $this->settings($kernel)->getString('tones.tone'), 'unchanged: kept');
        self::assertTrue($this->settings($kernel)->isOverridden('tones.tone'));

        // Typing a different colour that equals the preset still stores null.
        $this->settings($kernel)->save('tones', ['tone' => '#000000']);
        $this->resubmit($admin, ['tone' => '#93C5FD']);
        self::assertFalse($this->settings($kernel)->isOverridden('tones.tone'));
    }

    public function testAStoredCustomColourIsShownAndKept(): void
    {
        [$kernel, $admin] = $this->admin();
        $this->settings($kernel)->save('tones', ['tone' => '#ABCDEF']);

        self::assertSame('#abcdef', $this->hexValue($admin));
        self::assertSame('#abcdef', $admin->getCrawler()->filter('#settings_section_tone_swatch')->attr('value'));
        $this->resubmit($admin);
        self::assertSame('#abcdef', $this->settings($kernel)->getString('tones.tone'));
    }

    public function testEachResetButtonIsDescribedByItsFieldLabel(): void
    {
        [, $admin] = $this->admin();
        $crawler   = $admin->request('GET', '/admin/settings/sections/tones');

        $reset = $crawler->filter('[data-nowo-instance-settings-color-reset]');
        self::assertSame('settings_section_tone_label', $reset->attr('aria-describedby'));
        self::assertCount(1, $crawler->filter('label#settings_section_tone_label'));
    }

    public function testTheColourAssetsCarryTheRequestNonce(): void
    {
        $kernel    = $this->kernel();
        $container = $this->container($kernel);
        /** @var RequestStack $stack */
        $stack   = $container->get('request_stack');
        $request = Request::create('/admin/settings/sections/tones');
        $request->attributes->set('csp_nonce', 'nonce-test-1');
        $stack->push($request);
        /** @var Environment $twig */
        $twig = $container->get('twig');

        $html = $twig->render('@NowoInstanceSettingsBundle/settings/_color_assets.html.twig');
        self::assertStringContainsString('<script src="/bundles/nowoinstancesettings/js/nowo-instance-settings-color.js" defer nonce="nonce-test-1"', $html);
        self::assertStringContainsString('href="/bundles/nowoinstancesettings/css/nowo-instance-settings.css" nonce="nonce-test-1"', $html);

        $explicit = $twig->render('@NowoInstanceSettingsBundle/settings/_color_assets.html.twig', ['nonce' => 'explicit-2']);
        self::assertStringContainsString('nonce="explicit-2"', $explicit);
        $stack->pop();

        $none = $twig->render('@NowoInstanceSettingsBundle/settings/_color_assets.html.twig');
        self::assertStringNotContainsString('nonce=', $none);
    }

    /**
     * @return array{0: InstanceSettingsTestKernel, 1: KernelBrowser}
     */
    private function admin(): array
    {
        $kernel = $this->kernel();

        return [$kernel, $this->client($kernel)];
    }

    private function kernel(): InstanceSettingsTestKernel
    {
        return $this->bootKernel(['files' => ['orphan_retention' => 4]], [ColorDefaultFixtureSection::class => ColorDefaultFixtureSection::class]);
    }

    private function hexValue(KernelBrowser $admin): string
    {
        $crawler = $admin->request('GET', '/admin/settings/sections/tones');
        self::assertSame(200, $admin->getResponse()->getStatusCode());

        return (string) $crawler->filter('input[name="settings_section[tone]"]')->attr('value');
    }

    /**
     * Re-submits the rendered form (what a browser sends without JavaScript), with `$values` typed over it.
     *
     * @param array<string, string> $values
     */
    private function resubmit(KernelBrowser $admin, array $values = []): void
    {
        $current                     = $admin->request('GET', '/admin/settings/sections/tones')->filter('form[name="settings_section"]')->form()->getPhpValues();
        $current['settings_section'] = $values + $current['settings_section'];
        $admin->request('POST', '/admin/settings/sections/tones', $current);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
    }
}
