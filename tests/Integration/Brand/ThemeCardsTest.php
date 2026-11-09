<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Brand;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\EmbedHostController;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ThemeCardsFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\UiKitBundle\Appearance\BuiltInAppearancePresets;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * IS-2 (1.6): the theme-card picker in a real kernel: radio group markup, swatches from the preset tokens, escaping,
 * saving a card, and the plain select for hosts that do not opt in.
 */
final class ThemeCardsTest extends KernelTestCase
{
    public function testThePresetFieldsStayASelectByDefault(): void
    {
        $crawler = $this->client($this->brandKernel())->request('GET', '/admin/settings/sections/appearance');

        self::assertCount(1, $crawler->filter('select[name="settings_section[preset]"]'));
        self::assertCount(0, $crawler->filter('[data-nowo-instance-settings-theme-cards]'));
    }

    public function testPresetCardsRenderAnAccessibleRadioGroupAndSave(): void
    {
        $kernel  = $this->brandKernel(['preset_picker' => 'cards']);
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/admin/settings/sections/appearance');
        self::assertSame(200, $admin->getResponse()->getStatusCode());

        self::assertCount(0, $crawler->filter('select[name="settings_section[preset]"]'));
        $group = $crawler->filter('#settings_section_preset');
        self::assertSame('radiogroup', $group->attr('role'));
        self::assertSame('settings_section_preset_label', $group->attr('aria-labelledby'));
        self::assertSame('settings_section_preset_help', $group->attr('aria-describedby'));
        self::assertCount(1, $crawler->filter('label#settings_section_preset_label'), 'the row label names the group');
        self::assertStringContainsString('nowo-ui-cards', (string) $group->attr('class'));
        self::assertCount(0, $group->filter('[style]'), 'no style attribute: strict CSP safe');
        self::assertCount(0, $group->filter('script'));
        self::assertCount(0, $group->filter('label label'), 'one label per radio, never nested');
        self::assertCount($crawler->filter('#settings_section_preset label')->count(), $crawler->filter('#settings_section_preset label > input[type="radio"]'), 'every card label wraps its own radio');

        $radios = $crawler->filter('input[type="radio"][name="settings_section[preset]"]');
        self::assertGreaterThanOrEqual(8, $radios->count(), 'one native radio per preset (arrow keys move between them)');
        self::assertSame('checked', $crawler->filter('input[type="radio"][name="settings_section[preset]"][value="ocean"]')->attr('checked'), 'the configured default preset');

        $ocean = $crawler->filter('#settings_section_preset label[data-value="ocean"]');
        self::assertSame('Ocean', trim($ocean->filter('.nowo-ui-check__label')->text()));
        self::assertSame('true', $ocean->filter('svg')->attr('aria-hidden'));
        $palette = BuiltInAppearancePresets::get('ocean')->light->toArray();
        self::assertSame([$palette['paper'], $palette['surface'], $palette['ink'], $palette['accent'], $palette['accent_deep']], $ocean->filter('rect')->each(static fn ($rect): string => (string) $rect->attr('fill')));
        self::assertCount(0, $crawler->filter('#settings_section_preset label[data-value="custom"] svg'), 'custom: label only');
        $dark = BuiltInAppearancePresets::get('ocean')->dark->toArray();
        self::assertSame($dark['paper'], $crawler->filter('#settings_section_preset_dark label[data-value="ocean"] rect')->first()->attr('fill'), 'the dark field shows dark swatches');

        $form                             = $crawler->filter('form[name="settings_section"]')->form();
        $form['settings_section[preset]'] = 'ember';
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertSame('ember', $this->settings($kernel)->get('appearance.preset'));

        // An id outside the choices is refused as with the select.
        $crawler                              = $admin->request('GET', '/admin/settings/sections/appearance');
        $values                               = $crawler->filter('form[name="settings_section"]')->form()->getPhpValues();
        $values['settings_section']['preset'] = 'evil';
        $admin->request('POST', '/admin/settings/sections/appearance', $values);
        self::assertSame(422, $admin->getResponse()->getStatusCode());
        self::assertSame('ember', $this->settings($kernel)->get('appearance.preset'));
    }

    public function testHostCardsEscapeLabelsAndDropInvalidColours(): void
    {
        $admin   = $this->client($this->bootKernel(['files' => ['orphan_retention' => 4]], [ThemeCardsFixtureSection::class => ThemeCardsFixtureSection::class]));
        $crawler = $admin->request('GET', '/admin/settings/sections/look');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        $html = (string) $admin->getResponse()->getContent();

        self::assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Bold</b>', $html);
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('onmouseover', $html);
        self::assertSame(['#aabbcc'], $crawler->filter('label[data-value="bold"] rect')->each(static fn ($rect): string => (string) $rect->attr('fill')));
    }

    public function testCardsRenderInAnEmbeddedSection(): void
    {
        $kernel = $this->bootKernel(
            ['files' => ['orphan_retention' => 4]],
            [ThemeCardsFixtureSection::class => ThemeCardsFixtureSection::class, EmbedHostController::class => EmbedHostController::class],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->add('host_look', '/host/look')->controller([EmbedHostController::class, 'look'])->methods(['GET', 'POST']);
            },
        );
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/host/look');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-embedded="true"] [role="radiogroup"][data-nowo-instance-settings-theme-cards]'), 'the form theme is added on the embed too');
    }

    /**
     * @param array<string, mixed> $brand
     */
    private function brandKernel(array $brand = []): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            ['brand' => ['enabled' => true, 'defaults' => ['name' => 'Acme']] + $brand],
            fixtures: false,
            extensions: ['nowo_ui_kit' => ['appearance' => ['enabled' => true, 'defaults' => ['preset' => 'ocean', 'preset_dark' => 'obsidian']]]],
        );
    }
}
