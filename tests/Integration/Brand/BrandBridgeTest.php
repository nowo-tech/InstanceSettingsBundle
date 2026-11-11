<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Brand;

use Nowo\Brand\Contract\BrandAssetOrigin;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\InstanceSettingsBundle\Brand\InstanceBrandProvider;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\HostAppearanceProvider;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\LegacyBrandFallbacks;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\UiKitBundle\Appearance\AppearanceProviderInterface;
use Nowo\UiKitBundle\Appearance\ConfigAppearanceProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Twig\Environment;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function tempnam;

/**
 * The brand bridge in a real kernel (spec 220 S3): opt-in, UiKit provider take-over, byte-for-byte defaults, admin
 * pages with the image preview, the asset reader and the fallback seed.
 */
final class BrandBridgeTest extends KernelTestCase
{
    private const UI_KIT = ['appearance' => ['enabled' => true, 'defaults' => ['brand_name' => 'Acme', 'brand_eyebrow' => 'Since 1999', 'preset' => 'ocean', 'preset_dark' => 'obsidian', 'corner_style' => 'rounded']]];

    public function testWithoutBrandEnabledUiKitKeepsItsProvider(): void
    {
        $kernel    = $this->bootKernel(extensions: ['nowo_ui_kit' => self::UI_KIT]);
        $container = $this->container($kernel);

        self::assertFalse($container->has(BrandProviderInterface::class), 'no brand service: UiKit appearance.brand: auto changes nothing');
        self::assertInstanceOf(ConfigAppearanceProvider::class, $container->get(AppearanceProviderInterface::class));
        self::assertFalse($container->getParameter('nowo_instance_settings.files.enabled'));
    }

    public function testTheBridgeTakesOverAndAnEmptyStoreRendersAsTheConfigProvider(): void
    {
        $css    = $this->css($this->bootKernel(extensions: ['nowo_ui_kit' => self::UI_KIT]));
        $kernel = $this->brandKernel();

        $container = $this->container($kernel);
        self::assertInstanceOf(InstanceBrandProvider::class, $container->get(AppearanceProviderInterface::class));
        self::assertInstanceOf(InstanceBrandProvider::class, $container->get(BrandProviderInterface::class));
        // Spec 220 §3.4.3: same defaults, empty store => the same tokens, byte for byte.
        self::assertSame($css, $this->css($kernel));
        self::assertStringContainsString('--nowo-ui-', $css);
    }

    public function testAHostProviderWins(): void
    {
        $kernel = $this->brandKernel(['provider' => 'app.appearance']);

        self::assertInstanceOf(HostAppearanceProvider::class, $this->container($kernel)->get(AppearanceProviderInterface::class));
        self::assertInstanceOf(InstanceBrandProvider::class, $this->container($kernel)->get(BrandProviderInterface::class), 'the contract is still served');
    }

    public function testUiKitBrandOffKeepsTheConfigProvider(): void
    {
        // Audit IU-L1: the host keeps UiKit's look from config and serves the brand to the other consumers.
        $kernel = $this->brandKernel(['brand' => 'off']);

        self::assertInstanceOf(ConfigAppearanceProvider::class, $this->container($kernel)->get(AppearanceProviderInterface::class));
        self::assertInstanceOf(InstanceBrandProvider::class, $this->container($kernel)->get(BrandProviderInterface::class));
    }

    public function testBrandWithoutUiKitAppearanceStillServesTheContract(): void
    {
        $kernel = $this->bootKernel(['brand' => ['enabled' => true, 'defaults' => ['name' => 'Solo']]], fixtures: false);
        $brand  = $this->container($kernel)->get(BrandProviderInterface::class);
        self::assertInstanceOf(BrandProviderInterface::class, $brand);
        self::assertSame(['Solo', '#475569'], [$brand->getBrand()->name, $brand->getBrand()->light->primary]);
    }

    public function testAdminPagesUploadPreviewServeAndRead(): void
    {
        $kernel = $this->brandKernel();
        $admin  = $this->client($kernel);

        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-image-previews"]'), '1.12: no separate preview list');
        self::assertSame('empty', $crawler->filter('#settings_section_logo_light [data-nowo-ui-image-input]')->attr('data-nowo-ui-image-input-state'), 'nothing stored, no default: an empty box');
        // 1.12: the config default asset is shown in the box, marked as default.
        $markBox = $crawler->filter('#settings_section_mark [data-nowo-ui-image-input]');
        self::assertSame('default', $markBox->attr('data-nowo-ui-image-input-state'));
        self::assertMatchesRegularExpression('#^/brand/mark\.png\?v=[0-9a-f]{12}$#', (string) $markBox->filter('img')->attr('src'));
        self::assertNull($markBox->filter('[data-nowo-ui-image-input-badge]')->attr('hidden'), 'the Default badge is shown');
        self::assertSame('identity', $crawler->filter('input[name="settings_section[name]"]')->closest('[data-nowo-instance-settings-group]')?->attr('data-nowo-instance-settings-group'));

        $form                           = $crawler->filter('form[name="settings_section"]')->form();
        $form['settings_section[name]'] = 'Globex';
        self::upload($form, 'settings_section[logo_light][file]', $this->tempFile(ImageFactory::png(240, 80)));
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());

        $file = $this->settings($kernel)->getFile('brand.logo_light');
        self::assertNotNull($file);
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $html    = (string) $admin->getResponse()->getContent();
        $box     = $crawler->filter('#settings_section_logo_light [data-nowo-ui-image-input]');
        $img     = $box->filter('img');
        self::assertCount(1, $img);
        // Audit IO Q3: the file route URL only, never inline bytes; 1.12: in the field's own image input box.
        self::assertSame('stored', $box->attr('data-nowo-ui-image-input-state'));
        self::assertSame($file->url(), $img->attr('src'));
        self::assertSame('Logo (light backgrounds)', $img->attr('alt'));
        self::assertCount(1, $box->filter('input[type="checkbox"][name="settings_section[logo_light][remove]"]'), 'the remove checkbox sits in the box');
        self::assertStringNotContainsString('data:image', $html);
        self::assertStringNotContainsString('base64', $html);

        $brand = $this->container($kernel)->get(BrandProviderInterface::class);
        self::assertInstanceOf(BrandProviderInterface::class, $brand);
        $logo = $brand->getBrand()->asset('logo_light');
        self::assertSame([$file->url(), BrandAssetOrigin::Uploaded, 'Globex'], [$logo?->url, $logo?->origin, $brand->getBrand()->name]);
        $mark = $brand->getBrand()->asset('mark');
        self::assertSame(BrandAssetOrigin::ConfigDefault, $mark?->origin);
        self::assertMatchesRegularExpression('#^/brand/mark\.png\?v=[0-9a-f]{12}$#', $mark->url);

        $reader = $this->container($kernel)->get(BrandAssetReaderInterface::class);
        self::assertInstanceOf(BrandAssetReaderInterface::class, $reader);
        self::assertNotNull($logo);
        self::assertStringStartsWith("\x89PNG", $reader->read($logo));

        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', (string) $file->url());
        self::assertSame(200, $anonymous->getResponse()->getStatusCode(), 'brand images are public');

        // The UiKit header now prints the logo pair.
        $twig = $this->container($kernel)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        self::assertSame('Globex', $twig->createTemplate('{{ nowo_brand().name }}')->render());
    }

    public function testAppearancePageShowsContrastNotices(): void
    {
        $kernel = $this->brandKernel();
        $admin  = $this->client($kernel);

        $crawler = $admin->request('GET', '/admin/settings/sections/appearance');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-notice"]'));

        $form                                = $crawler->filter('form[name="settings_section"]')->form();
        $form['settings_section[light_ink]'] = '#F0F9FB';
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertSame('#f0f9fb', $this->settings($kernel)->get('appearance.light_ink'));

        $crawler = $admin->request('GET', '/admin/settings/sections/appearance');
        self::assertGreaterThan(0, $crawler->filter('[data-testid="instance-settings-notice"]')->count());
        self::assertStringContainsString('text on the page background', $crawler->filter('[data-testid="instance-settings-notice"]')->first()->text());
        self::assertStringNotContainsString('#f0f9fb', $crawler->filter('[data-testid="instance-settings-notice"]')->text());
    }

    public function testSeedFromTheHostFallbackProvider(): void
    {
        $kernel = $this->brandKernel(brand: ['fallback_provider' => LegacyBrandFallbacks::class], services: [LegacyBrandFallbacks::class => LegacyBrandFallbacks::class]);

        $tester = new CommandTester((new Application($kernel))->find('nowo:instance-settings:seed-from-fallbacks'));
        self::assertSame(0, $tester->execute(['--section' => ['brand', 'appearance']]));
        $settings = $this->settings($kernel);
        $settings->reset();
        self::assertSame('Legacy', $settings->get('brand.name'));
        self::assertSame('#123456', $settings->get('appearance.light_accent'));
        self::assertFalse($settings->isOverridden('appearance.dark_accent'), 'equal to the preset: not seeded');
    }

    /**
     * @param array<string, mixed> $uiKit extra nowo_ui_kit.appearance options
     * @param array<string, mixed> $brand extra brand options
     * @param array<string, string> $services
     */
    private function brandKernel(array $uiKit = [], array $brand = [], array $services = []): InstanceSettingsTestKernel
    {
        $public = InstanceSettingsTestKernel::baseDir() . '/public-' . $this->name();
        if (!is_dir($public . '/brand')) {
            mkdir($public . '/brand', 0o777, true);
        }
        file_put_contents($public . '/brand/mark.png', ImageFactory::png(64, 64));

        return $this->bootKernel(
            ['brand' => [
                'enabled'    => true,
                'public_dir' => $public,
                'defaults'   => ['name' => 'Acme', 'eyebrow' => 'Since 1999', 'mark' => 'brand/mark.png'],
            ] + $brand],
            $services + ['app.appearance' => HostAppearanceProvider::class],
            fixtures: false,
            extensions: ['nowo_ui_kit' => ['appearance' => self::UI_KIT['appearance'] + $uiKit]],
        );
    }

    private function css(InstanceSettingsTestKernel $kernel): string
    {
        $twig = $this->container($kernel)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->createTemplate('{{ nowo_ui_appearance_css() }}')->render();
    }

    private function tempFile(string $bytes): string
    {
        $path = (string) tempnam(InstanceSettingsTestKernel::baseDir(), 'up');
        file_put_contents($path, $bytes);

        return $path;
    }
}
