<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Brand\BrandPalettes;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ColorFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\EmbedHostController;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\UiKitBundle\Appearance\BuiltInAppearancePresets;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function array_keys;
use function count;
use function json_decode;
use function ksort;
use function sort;
use function strtolower;
use function strtoupper;

use const JSON_THROW_ON_ERROR;

/**
 * 1.12: the colour field in a real kernel: markup (swatch labelled by the row label, never submitted; hex input is the
 * value), assets, the no-JS submit path (hex only) for null / preset / custom / invalid values, the preset source, the
 * brand appearance colours and BC of String + Regex colours.
 */
final class ColorFieldTest extends KernelTestCase
{
    public function testTheRowPairsASwatchWithTheHexInput(): void
    {
        [, $crawler] = $this->page();

        $row = $crawler->filter('[data-nowo-instance-settings-color]')->first();
        self::assertSame('#1d4ed8', $row->attr('data-preset'));
        $swatch = $row->filter('input[type="color"]');
        self::assertCount(1, $swatch);
        self::assertNull($swatch->attr('name'), 'the swatch is never submitted');
        self::assertSame('#1d4ed8', $swatch->attr('value'), 'empty value: the swatch shows the preset');
        self::assertSame('settings_section_accent_label', $swatch->attr('aria-labelledby'));
        self::assertSame('settings_section_accent_help', $swatch->attr('aria-describedby'));
        self::assertCount(1, $crawler->filter('label#settings_section_accent_label[for="settings_section_accent"]'), 'the row label names the swatch and the hex input');
        self::assertNotNull($swatch->attr('hidden'), 'hidden until the script runs');

        $hex = $row->filter('input[type="text"][name="settings_section[accent]"]');
        self::assertCount(1, $hex);
        self::assertSame('', (string) $hex->attr('value'), 'nothing stored: empty (follow the preset), not the default');
        self::assertSame('#1d4ed8', $hex->attr('placeholder'));
        self::assertSame('7', $hex->attr('maxlength'));
        self::assertSame($crawler->filter('input[name="settings_section[legacy]"]')->attr('class'), $hex->attr('class'), 'the hex input is rendered by the form theme chain like any text input');
        self::assertSame('settings_section_accent_help', $hex->attr('aria-describedby'));

        $reset = $row->filter('button[type="button"][data-nowo-instance-settings-color-reset]');
        self::assertSame('Use the preset colour', trim($reset->text()));
        self::assertNotNull($reset->attr('hidden'));
        self::assertStringContainsString('nowo-ui-btn', (string) $reset->attr('class'));

        // Strict CSP: no inline handler, no style attribute, no inline script.
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-color] [style], [data-nowo-instance-settings-color][style]'));
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', (string) $row->outerHtml());
        self::assertCount(1, $crawler->filter('script[src$="/bundles/nowoinstancesettings/js/nowo-instance-settings-color.js"][defer]'));
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href$="/bundles/nowoinstancesettings/css/nowo-instance-settings.css"]'));
        self::assertCount(0, $crawler->filter('script:not([src])[data-nowo-instance-settings-color-script]'));
        self::assertStringNotContainsString('style="', (string) $row->outerHtml());

        // BC: a String + Regex colour stays a plain text input.
        self::assertCount(1, $crawler->filter('input[type="text"][name="settings_section[legacy]"]'));
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-color] input[name="settings_section[legacy]"]'));
    }

    public function testThePresetFollowsTheSourceField(): void
    {
        [, $crawler] = $this->page();

        $row = $crawler->filter('[data-nowo-instance-settings-color]')->eq(1);
        self::assertSame('settings_section[scheme]', $row->attr('data-preset-from'));
        self::assertSame(['dark' => '#ddeeff', 'light' => '#112233'], $this->sorted(json_decode((string) $row->attr('data-preset-map'), true, 512, JSON_THROW_ON_ERROR)));
        self::assertNull($row->attr('data-preset'));
        self::assertSame('#112233', $row->filter('input[type="color"]')->attr('value'));
        self::assertSame('', (string) $row->filter('input[name="settings_section[link]"]')->attr('value'));
        self::assertSame('#112233', $row->filter('input[name="settings_section[link]"]')->attr('placeholder'));
    }

    public function testNoJsSubmitPathSavesNullPresetCustomAndRefusesInvalid(): void
    {
        $kernel  = $this->kernel();
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/admin/settings/sections/palette');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        $names   = array_keys($form->getPhpValues()['settings_section']);
        sort($names);
        self::assertSame(['_token', 'accent', 'legacy', 'link', 'scheme'], $names, 'without JavaScript only the hex inputs are posted');

        // Custom colours are lower-cased and stored.
        $this->post($admin, ['accent' => '#ABCDEF', 'link' => '#00FF00', 'legacy' => '#ABCDEF']);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        $settings = $this->settings($kernel);
        self::assertSame('#abcdef', $settings->getString('palette.accent'));
        self::assertSame('#00ff00', $settings->getString('palette.link'));
        self::assertSame('#ABCDEF', $settings->getString('palette.legacy'), 'BC: a String colour is stored as typed');

        // The preset colour (any case) is saved as null: the default / the source colour takes over.
        $this->post($admin, ['accent' => '#1D4ED8', 'link' => '#112233']);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        $settings = $this->settings($kernel);
        self::assertFalse($settings->isOverridden('palette.accent'));
        self::assertSame('#1d4ed8', $settings->getString('palette.accent'));
        self::assertFalse($settings->isOverridden('palette.link'));
        self::assertNull($settings->getString('palette.link'));

        // The preset of the submitted source value counts: #112233 is custom once the scheme is dark.
        $this->post($admin, ['scheme' => 'dark', 'link' => '#112233']);
        self::assertSame('#112233', $this->settings($kernel)->getString('palette.link'));
        $this->post($admin, ['scheme' => 'dark', 'link' => '#ddeeff']);
        self::assertFalse($this->settings($kernel)->isOverridden('palette.link'));

        // Empty = null (follow the preset).
        $this->post($admin, ['accent' => '#000000']);
        self::assertTrue($this->settings($kernel)->isOverridden('palette.accent'));
        $this->post($admin, ['accent' => '  ']);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertFalse($this->settings($kernel)->isOverridden('palette.accent'));

        // Invalid: refused next to the field, nothing stored, the swatch falls back to the preset.
        $crawler = $this->post($admin, ['accent' => 'red', 'link' => '#12345']);
        self::assertSame(422, $admin->getResponse()->getStatusCode());
        self::assertStringContainsString('This value is not a #rrggbb colour.', (string) $admin->getResponse()->getContent());
        self::assertFalse($this->settings($kernel)->isOverridden('palette.accent'));
        self::assertSame('red', $crawler->filter('input[name="settings_section[accent]"]')->attr('value'));
        self::assertSame('#1d4ed8', $crawler->filter('#settings_section_accent_swatch')->attr('value'));
        self::assertSame('#ddeeff', $crawler->filter('#settings_section_link_swatch')->attr('value'), 'the submitted source value picks the preset');
    }

    public function testTheEmbedLoadsTheColourAssetsToo(): void
    {
        $kernel = $this->bootKernel(
            ['files' => ['orphan_retention' => 4]],
            [ColorFixtureSection::class => ColorFixtureSection::class, EmbedHostController::class => EmbedHostController::class],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->add('host_palette', '/host/palette')->controller([EmbedHostController::class, 'palette'])->methods(['GET', 'POST']);
            },
        );
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/host/palette');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertCount(2, $crawler->filter('[data-embedded="true"] [data-nowo-instance-settings-color]'));
        self::assertCount(1, $crawler->filter('script[data-nowo-instance-settings-color-script]'));
    }

    public function testBrandAppearanceColoursUseTheColourInput(): void
    {
        $kernel = $this->bootKernel(
            ['brand' => ['enabled' => true, 'defaults' => ['name' => 'Acme']]],
            fixtures: false,
            extensions: ['nowo_ui_kit' => ['appearance' => ['enabled' => true, 'defaults' => ['preset' => 'ocean', 'preset_dark' => 'obsidian']]]],
        );
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/admin/settings/sections/appearance');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertCount(2 * count(BrandPalettes::KEYS), $crawler->filter('[data-nowo-instance-settings-color]'));

        $ocean = BuiltInAppearancePresets::get('ocean')->light->toArray();
        $row   = $crawler->filter('#settings_section_light_accent')->closest('[data-nowo-instance-settings-color]');
        self::assertInstanceOf(Crawler::class, $row);
        self::assertSame('settings_section[preset]', $row->attr('data-preset-from'));
        self::assertSame(strtolower($ocean['accent']), $row->filter('input[type="color"]')->attr('value'));
        self::assertSame('appearance', $crawler->filter('[data-section]')->attr('data-section'));
        self::assertCount(1, $crawler->filter('#settings_section_light_accent_help'), 'help text kept');
        self::assertSame('colors.light', $crawler->filter('#settings_section_light_accent')->closest('[data-nowo-instance-settings-group]')?->attr('data-nowo-instance-settings-group'));

        $form                                   = $crawler->filter('form[name="settings_section"]')->form();
        $form['settings_section[light_accent]'] = strtoupper($ocean['accent']);
        $form['settings_section[dark_accent]']  = '#123456';
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertFalse($this->settings($kernel)->isOverridden('appearance.light_accent'), 'the preset colour is not stored');
        self::assertSame('#123456', $this->settings($kernel)->getString('appearance.dark_accent'));

        $values                                     = $admin->request('GET', '/admin/settings/sections/appearance')->filter('form[name="settings_section"]')->form()->getPhpValues();
        $values['settings_section']['light_accent'] = 'blue';
        $admin->request('POST', '/admin/settings/sections/appearance', $values);
        self::assertSame(422, $admin->getResponse()->getStatusCode());
        self::assertStringContainsString('This value is not a #rrggbb colour.', (string) $admin->getResponse()->getContent());
    }

    /**
     * @return array{0: KernelBrowser, 1: Crawler}
     */
    private function page(): array
    {
        $admin   = $this->client($this->kernel());
        $crawler = $admin->request('GET', '/admin/settings/sections/palette');
        self::assertSame(200, $admin->getResponse()->getStatusCode());

        return [$admin, $crawler];
    }

    /**
     * Posts the section form with `$values` over the rendered ones (what a browser without JavaScript sends).
     *
     * @param array<string, string> $values
     */
    private function post(KernelBrowser $admin, array $values): Crawler
    {
        $current                     = $admin->request('GET', '/admin/settings/sections/palette')->filter('form[name="settings_section"]')->form()->getPhpValues();
        $current['settings_section'] = $values + $current['settings_section'];

        return $admin->request('POST', '/admin/settings/sections/palette', $current);
    }

    private function kernel(): InstanceSettingsTestKernel
    {
        return $this->bootKernel(['files' => ['orphan_retention' => 4]], [ColorFixtureSection::class => ColorFixtureSection::class]);
    }

    /**
     * @return array<mixed>
     */
    private function sorted(mixed $map): array
    {
        self::assertIsArray($map);
        ksort($map);

        return $map;
    }
}
