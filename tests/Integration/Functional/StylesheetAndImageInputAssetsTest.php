<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\PlainImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;

use function dirname;
use function file_get_contents;
use function preg_replace;
use function str_contains;
use function str_ends_with;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * 1.13 (audit LM29-I1 / LM29-I3): the bundle stylesheet sits in UiKit's cascade layer (the unlayered build on demand),
 * and an image field with `image_input: false` keeps its plain row without loading UiKit's image-input assets.
 */
final class StylesheetAndImageInputAssetsTest extends KernelTestCase
{
    private const CSS = '/bundles/nowoinstancesettings/css/';

    public function testLayeredByDefaultUnlayeredOnDemand(): void
    {
        $admin   = $this->client($this->bootKernel(['files' => ['enabled' => true]], services: [ImageFixtureSection::class => ImageFixtureSection::class]));
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        self::assertCount(1, $crawler->filter('link[href$="' . self::CSS . 'nowo-instance-settings.css"]'));
        self::assertCount(0, $crawler->filter('link[href$="unlayered.css"]'));

        $admin   = $this->client($this->bootKernel(['files' => ['enabled' => true], 'web_ui' => ['unlayered_styles' => true]], services: [ImageFixtureSection::class => ImageFixtureSection::class]));
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        self::assertCount(1, $crawler->filter('link[href$="' . self::CSS . 'nowo-instance-settings.unlayered.css"]'));
        self::assertCount(0, $crawler->filter('link[href$="' . self::CSS . 'nowo-instance-settings.css"]'));
    }

    public function testBothStylesheetsHoldTheSameRules(): void
    {
        $dir       = dirname(__DIR__, 3) . '/src/Resources/public/css/';
        $layered   = (string) file_get_contents($dir . 'nowo-instance-settings.css');
        $unlayered = (string) file_get_contents($dir . 'nowo-instance-settings.unlayered.css');

        $open = "@layer theme, base, nowo-ui, components, utilities;\n\n@layer nowo-ui {\n";
        self::assertTrue(str_contains($layered, $open), 'UiKit layer order, then the nowo-ui layer');
        $body = trim(substr($layered, (int) strpos($layered, $open) + strlen($open)));
        self::assertTrue(str_ends_with($body, '}'), 'the layer block closes the file');
        // The unlayered header comment (the first comment) is not part of the rules.
        $rules = substr($unlayered, (int) strpos($unlayered, '*/') + 2);
        self::assertSame($this->squash($rules), $this->squash(substr($body, 0, -1)));
        self::assertFalse(str_contains($rules, '@layer'));
    }

    public function testImageInputFalseKeepsThePlainRowWithoutImageInputAssets(): void
    {
        $admin   = $this->client($this->bootKernel(['files' => ['enabled' => true]], services: [PlainImageFixtureSection::class => PlainImageFixtureSection::class]));
        $crawler = $admin->request('GET', '/admin/settings/sections/plain_images');
        self::assertSame(200, $admin->getResponse()->getStatusCode());

        self::assertCount(0, $crawler->filter('[data-nowo-ui-image-input]'));
        self::assertCount(0, $crawler->filter('.' . SettingsSectionType::IMAGE_CARD_CLASS));
        self::assertCount(0, $crawler->filter('script[src$="nowo-ui-image-input.js"]'));
        self::assertCount(0, $crawler->filter('link[data-nowo-instance-settings-styles]'));
        // The row keeps the field label and help (pre-1.12 markup).
        self::assertSame('Banner', $crawler->filter('#settings_section_banner')->ancestors()->first()->filter('label')->first()->text());
        self::assertSame('Shown on the home page.', trim($crawler->filter('#settings_section_banner_help')->text()));
        self::assertCount(1, $crawler->filter('input[type="file"][name="settings_section[banner][file]"]'));
    }

    private function squash(string $css): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $css));
    }
}
