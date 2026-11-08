<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Brand;

use DateTimeImmutable;
use Error;
use Nowo\Brand\Contract\BrandAssetOrigin;
use Nowo\Brand\Contract\BrandAssetSlot;
use Nowo\InstanceSettingsBundle\Brand\BrandDefaults;
use Nowo\InstanceSettingsBundle\Brand\BrandPalettes;
use Nowo\InstanceSettingsBundle\Brand\DefaultAsset;
use Nowo\InstanceSettingsBundle\Brand\TranslatableBrandText;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Tests\Support\BrandFixture;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\UiKitBundle\Appearance\AppearancePresetRegistry;
use Nowo\UiKitBundle\Appearance\ConfigAppearanceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;
use Throwable;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * The bridge: brand contract and UiKit appearance from the two sections, defaults, uploads and failures.
 */
final class InstanceBrandProviderTest extends TestCase
{
    public function testEmptyStoreGivesTheConfiguredDefaults(): void
    {
        $fixture = new BrandFixture(new BrandDefaults('Acme', eyebrowText: 'Since 1999', wordmark: 'acme'));
        $brand   = $fixture->provider->getBrand();

        self::assertSame('Acme', $brand->name);
        self::assertSame('Acme', $brand->shortName);
        self::assertSame('Since 1999', $brand->eyebrow);
        self::assertSame('acme', $brand->wordmark);
        self::assertTrue($brand->isDefault);
        self::assertSame([], $brand->assets);
        // Kit defaults: slate light, midnight dark.
        self::assertSame('#475569', $brand->light->primary);
        self::assertSame('#0f1524', $brand->dark->surface);
    }

    public function testEmptyStoreAppearanceEqualsUiKitConfigProvider(): void
    {
        $registry = new AppearancePresetRegistry();
        $config   = new ConfigAppearanceProvider($registry, ['brand_name' => 'Acme', 'brand_eyebrow' => 'Since 1999', 'preset' => 'ocean', 'preset_dark' => 'obsidian', 'corner_style' => 'rounded', 'border_strength' => 'strong', 'footer_fixed' => true]);
        $fixture  = new BrandFixture(new BrandDefaults('Acme', eyebrowText: 'Since 1999'), new BrandPalettes($config, $registry));

        // Spec 220 §3.4.3: the bridge with an empty store equals the config provider (same defaults).
        self::assertEquals($config->getAppearance(), $fixture->provider->getAppearance());
        self::assertNull($fixture->provider->getAppearance()->logos);
    }

    public function testStoredValuesWin(): void
    {
        $fixture = new BrandFixture();
        $fixture->settings->save('brand', ['name' => 'Globex', 'short_name' => 'GLX', 'eyebrow' => 'Hello', 'wordmark' => 'globex inc']);
        $fixture->settings->save('appearance', ['preset' => 'ocean', 'light_accent' => '#AA0000', 'dark_paper' => '#010203', 'corner_style' => 'sharp', 'border_strength' => 'subtle', 'footer_fixed' => '1']);

        $brand = $fixture->provider->getBrand();
        self::assertSame(['Globex', 'GLX', 'Hello', 'globex inc', false], [$brand->name, $brand->shortName, $brand->eyebrow, $brand->wordmark, $brand->isDefault]);
        self::assertSame('#aa0000', $brand->light->primary);
        self::assertSame('#010203', $brand->dark->paper);

        $appearance = $fixture->provider->getAppearance();
        self::assertSame('Globex', $appearance->brandName);
        self::assertSame('Hello', $appearance->brandEyebrow);
        self::assertSame('custom', $appearance->themeId, 'a colour differs from the preset');
        self::assertSame('custom', $appearance->themeIdDark, 'the dark paper differs from midnight');
        self::assertSame(['sharp', 'subtle', true], [$appearance->cornerStyle, $appearance->borderStrength, $appearance->footerFixed]);
    }

    public function testDefaultEyebrowKeyFollowsTheLocale(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['brand.eyebrow' => 'Your operations'], 'en', 'messages');
        $translator->addResource('array', ['brand.eyebrow' => 'Tus operaciones'], 'es', 'messages');
        $fixture = new BrandFixture(new BrandDefaults('Acme', eyebrowKey: 'brand.eyebrow'), translator: $translator);

        $eyebrow = $fixture->provider->getBrand()->eyebrow;
        self::assertInstanceOf(TranslatableBrandText::class, $eyebrow);
        self::assertSame('Your operations', $fixture->provider->getAppearance()->brandEyebrow);
        $translator->setLocale('es');
        self::assertSame('Tus operaciones', $fixture->provider->getAppearance()->brandEyebrow);

        // A stored eyebrow is a literal.
        $fixture->settings->save('brand', ['eyebrow' => 'Fixed']);
        self::assertSame('Fixed', $fixture->provider->getAppearance()->brandEyebrow);
    }

    public function testATranslatedEyebrowWithUnsafeCharactersIsDropped(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['brand.eyebrow' => "Evil\u{202E}txt"], 'en', 'messages');
        $fixture = new BrandFixture(new BrandDefaults('Acme', eyebrowKey: 'brand.eyebrow'), translator: $translator);

        self::assertSame('', $fixture->provider->getAppearance()->brandEyebrow);
    }

    public function testUploadsBecomeAssetsAndLogos(): void
    {
        $fixture = new BrandFixture();
        $fixture->settings->save('brand', [
            'logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80)),
            'mark'       => ImageUpload::fromBytes(ImageFactory::png(512, 512)),
        ]);

        $brand = $fixture->provider->getBrand();
        $logo  = $brand->asset(BrandAssetSlot::LOGO_LIGHT);
        self::assertNotNull($logo);
        self::assertSame(BrandAssetOrigin::Uploaded, $logo->origin);
        self::assertStringStartsWith('/_settings-file/brand/logo_light/', $logo->url);
        self::assertSame([240, 80, 'image/png'], [$logo->width, $logo->height, $logo->mimeType]);
        self::assertFalse($logo->isInlineSafe());
        // Contract chain: app_icon -> a square mark of at least 512.
        self::assertSame(BrandAssetSlot::MARK, $brand->asset(BrandAssetSlot::APP_ICON)?->slot);
        self::assertFalse($brand->isDefault);

        $logos = $fixture->provider->getAppearance()->logos;
        self::assertNotNull($logos);
        self::assertSame($logo->url, $logos->lightSrc);
        self::assertSame($brand->asset(BrandAssetSlot::MARK)?->url, $logos->markSrc);
        self::assertSame($logos->markSrc, $logos->faviconSrc, 'favicon -> mark');
        self::assertSame('acme', $logos->wordmark);
    }

    public function testUploadsWithoutTheFileRouteAreSkipped(): void
    {
        $fixture = new BrandFixture(router: false);
        $fixture->settings->save('brand', ['logo_light' => ImageUpload::fromBytes(ImageFactory::png(240, 80))]);

        self::assertNull($fixture->provider->getBrand()->asset(BrandAssetSlot::LOGO_LIGHT));
    }

    public function testConfigDefaultAssetsAndExtras(): void
    {
        $dir = sys_get_temp_dir() . '/nowo-brand-' . uniqid();
        mkdir($dir . '/brand', 0o777, true);
        file_put_contents($dir . '/brand/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        file_put_contents($dir . '/brand/mascot.png', ImageFactory::png(40, 40));
        $defaults = new BrandDefaults(
            'Acme',
            assets: [
                'logo_light'   => DefaultAsset::fromFile('logo_light', 'brand/logo.svg', $dir)->toArray(),
                'extra.mascot' => DefaultAsset::fromFile('extra.mascot', 'brand/mascot.png', $dir)->toArray(),
            ],
            extraImages: ['mascot' => ['label' => null, 'help' => null, 'max_width' => 400, 'max_height' => 400, 'max_bytes' => 100_000, 'square' => false]],
        );
        $fixture = new BrandFixture($defaults);

        $brand = $fixture->provider->getBrand();
        $logo  = $brand->asset(BrandAssetSlot::LOGO_LIGHT);
        self::assertNotNull($logo);
        self::assertSame(['/brand/logo.svg', 'image/svg+xml', null, BrandAssetOrigin::ConfigDefault], [$logo->url, $logo->mimeType, $logo->width, $logo->origin]);
        self::assertTrue($logo->isInlineSafe());
        self::assertSame('/brand/mascot.png', $brand->asset('extra.mascot')?->url);
        self::assertTrue($brand->isDefault, 'config defaults are not stored values');

        // An upload overrides the extra default.
        $fixture->settings->save('brand', ['extra_mascot' => ImageUpload::fromBytes(ImageFactory::png(50, 50))]);
        self::assertSame(BrandAssetOrigin::Uploaded, $fixture->provider->getBrand()->asset('extra.mascot')?->origin);
    }

    public function testAStoredTextTheContractRefusesFallsBackToTheDefaults(): void
    {
        $fixture = new BrandFixture(new BrandDefaults('Acme', shortName: 'ACME'));
        // Written outside the form (no constraint): a bidi override.
        $fixture->repository->apply([(new SettingValue('brand', 'name', new DateTimeImmutable()))->storeValue("Evil\u{202E}name", new DateTimeImmutable())], []);

        $brand = $fixture->provider->getBrand();
        self::assertSame(['Acme', 'ACME', false], [$brand->name, $brand->shortName, $brand->isDefault]);
    }

    public function testAStoreFailureIsLoggedAndGivesTheDefaults(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = $level . ' ' . $message;
            }
        };
        $failing = $this->failingRepository(new RuntimeException('SQLSTATE db down'));
        $fixture = new BrandFixture(logger: $logger, repository: $failing);

        self::assertSame('Acme', $fixture->provider->getBrand()->name);
        self::assertSame('Acme', $fixture->provider->getAppearance()->brandName);
        self::assertNotEmpty($logger->messages);
        self::assertStringStartsWith('warning Instance settings brand', $logger->messages[0]);
    }

    public function testProgrammingErrorsAreNotSwallowed(): void
    {
        $fixture = new BrandFixture(repository: $this->failingRepository(new Error('bug')));

        $this->expectException(Error::class);
        $fixture->provider->getBrand();
    }

    public function testVersionChangesWithTheStore(): void
    {
        $fixture = new BrandFixture();
        $before  = $fixture->provider->getBrand()->version;
        $fixture->settings->save('appearance', ['light_accent' => '#123456']);

        self::assertNotSame($before, $fixture->provider->getBrand()->version);
    }

    private function failingRepository(Throwable $error): SettingValueRepositoryInterface
    {
        return new class($error) implements SettingValueRepositoryInterface {
            public function __construct(private readonly Throwable $error)
            {
            }

            public function findAll(): array
            {
                throw $this->error;
            }

            public function findBySection(string $section): array
            {
                throw $this->error;
            }

            public function apply(array $save, array $remove): void
            {
            }

            public function transactional(callable $callback): mixed
            {
                return $callback();
            }
        };
    }
}
