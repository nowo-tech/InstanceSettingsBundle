<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\Brand\Contract\BrandAssetReaderInterface;
use Nowo\Brand\Contract\BrandProviderInterface;
use Nowo\InstanceSettingsBundle\Brand\AppearanceSection;
use Nowo\InstanceSettingsBundle\Brand\BrandDefaults;
use Nowo\InstanceSettingsBundle\Brand\BrandSection;
use Nowo\InstanceSettingsBundle\Brand\BrandTwigExtension;
use Nowo\InstanceSettingsBundle\Brand\InstanceBrandAssetReader;
use Nowo\InstanceSettingsBundle\Brand\InstanceBrandProvider;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\BrandBridgePass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Configuration;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\File\SettingFileStoreInterface;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Filesystem\Filesystem;

use function file_put_contents;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;

/**
 * `brand` configuration and services (1.5): opt-in, implies the files, default assets read at compile time, the file
 * mapping decided in this extension's prepend (audit IO-I10).
 */
final class BrandExtensionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-brand-ext-' . uniqid();
        mkdir($this->dir . '/brand', 0o777, true);
        file_put_contents($this->dir . '/brand/mark.png', ImageFactory::png(32, 32));
        file_put_contents($this->dir . '/brand/mascot.png', ImageFactory::png(20, 10));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testOffByDefaultRegistersNothing(): void
    {
        $container = $this->load([]);

        self::assertFalse($container->getParameter(BrandBridgePass::PARAMETER));
        self::assertFalse($container->getParameter('nowo_instance_settings.files.enabled'));
        foreach ([InstanceBrandProvider::class, BrandSection::class, AppearanceSection::class, BrandTwigExtension::class, InstanceBrandAssetReader::class, BrandDefaults::class] as $id) {
            self::assertFalse($container->hasDefinition($id), $id);
        }
        self::assertFalse($container->hasAlias(BrandProviderInterface::class));
        $processed = (new Processor())->processConfiguration(new Configuration(), []);
        self::assertSame([false, '%kernel.project_dir%/public', null], [$processed['brand']['enabled'], $processed['brand']['public_dir'], $processed['brand']['fallback_provider']]);
    }

    public function testEnabledRegistersTheBridgeAndImpliesTheFiles(): void
    {
        $container = $this->load(['brand' => [
            'enabled'           => true,
            'public_dir'        => $this->dir,
            'fallback_provider' => 'app.brand_fallbacks',
            'defaults'          => ['name' => 'Acme', 'short_name' => 'AC', 'eyebrow' => ['key' => 'brand.eyebrow', 'domain' => 'app'], 'wordmark' => 'acme', 'mark' => 'brand/mark.png'],
            'extra_images'      => ['mascot' => ['label' => 'app.mascot', 'default' => 'brand/mascot.png', 'max_width' => 200]],
        ]]);

        self::assertTrue($container->getParameter(BrandBridgePass::PARAMETER));
        self::assertTrue($container->getParameter('nowo_instance_settings.files.enabled'), 'brand.enabled implies the files');
        self::assertTrue($container->getDefinition(SettingsSectionRegistry::class)->getArgument(2));
        self::assertTrue($container->has(SettingFileStoreInterface::class));
        self::assertSame(InstanceBrandProvider::class, (string) $container->getAlias(BrandProviderInterface::class));
        self::assertTrue($container->getAlias(BrandProviderInterface::class)->isPublic());
        self::assertSame(InstanceBrandAssetReader::class, (string) $container->getAlias(BrandAssetReaderInterface::class));
        self::assertTrue($container->getDefinition(BrandSection::class)->hasTag(NowoInstanceSettingsBundle::SECTION_TAG));
        self::assertTrue($container->getDefinition(AppearanceSection::class)->hasTag(NowoInstanceSettingsBundle::SECTION_TAG));
        self::assertTrue($container->getDefinition(BrandTwigExtension::class)->hasTag('twig.extension'));
        self::assertEquals(new Reference('app.brand_fallbacks'), $container->getDefinition(BrandSection::class)->getArgument(1));

        $defaults = $container->getDefinition(BrandDefaults::class)->getArguments();
        self::assertSame(['Acme', 'AC', null, 'brand.eyebrow', 'app', 'acme'], [$defaults[0], $defaults[1], $defaults[2], $defaults[3], $defaults[4], $defaults[5]]);
        self::assertSame(['mark', 'extra.mascot'], array_keys($defaults[6]));
        self::assertSame(['/brand/mark.png?v=' . substr($defaults[6]['mark']['sha256'], 0, 12), 'image/png', 32], [$defaults[6]['mark']['url'], $defaults[6]['mark']['mime_type'], $defaults[6]['mark']['width']]);
        self::assertEquals(['label' => 'app.mascot', 'help' => null, 'max_width' => 200, 'max_height' => 4096, 'max_bytes' => 1_048_576, 'square' => false], $defaults[7]['mascot']);

        $resources = array_map(static fn (object $r): string => (string) $r, $container->getResources());
        self::assertContains((string) new FileResource($this->dir . '/brand/mark.png'), $resources, 'the default file rebuilds the container in debug');

        $defaults = (new BrandDefaults(...$container->getDefinition(BrandDefaults::class)->getArguments()));
        self::assertStringStartsWith('/brand/mascot.png?v=', (string) $defaults->asset('extra.mascot')?->url);
    }

    public function testStringEyebrowAndNoFallbackProvider(): void
    {
        $container = $this->load(['brand' => ['enabled' => true, 'defaults' => ['name' => 'Acme', 'eyebrow' => 'Since 1999']]]);

        self::assertSame('Since 1999', $container->getDefinition(BrandDefaults::class)->getArgument(2));
        self::assertNull($container->getDefinition(BrandDefaults::class)->getArgument(3));
        self::assertNull($container->getDefinition(BrandSection::class)->getArgument(1));
    }

    public function testInvalidBrandConfiguration(): void
    {
        foreach ([
            'name required'      => [['enabled' => true], 'defaults.name is required'],
            'name too long'      => [['defaults' => ['name' => str_repeat('a', 81)]], 'at most 80'],
            'name control chars' => [['defaults' => ['name' => "a\nb"]], 'defaults.name'],
            'short name'         => [['defaults' => ['short_name' => 'ThirteenChars']], 'short_name'],
            'blank wordmark'     => [['defaults' => ['wordmark' => '  ']], 'wordmark'],
            'eyebrow both'       => [['defaults' => ['eyebrow' => ['text' => 'a', 'key' => 'b']]], 'not both'],
            'eyebrow key'        => [['defaults' => ['eyebrow' => ['key' => '']]], 'eyebrow.key'],
            'eyebrow text'       => [['defaults' => ['eyebrow' => str_repeat('e', 121)]], 'eyebrow'],
            'extra id'           => [['extra_images' => ['Bad-Id' => []]], 'extra_images ids'],
            'placeholder switch' => [['enabled' => '%env(bool:BRAND)%', 'defaults' => ['name' => 'A']], 'brand.enabled'],
        ] as $case => [$brand, $message]) {
            try {
                $this->load(['brand' => $brand]);
                self::fail($case);
            } catch (InvalidConfigurationException $e) {
                self::assertStringContainsString($message, $e->getMessage(), $case);
            }
        }
    }

    public function testAnUnsafeDefaultAssetFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_instance_settings.brand: Brand default asset "mark"');
        $this->load(['brand' => ['enabled' => true, 'public_dir' => $this->dir, 'defaults' => ['name' => 'A', 'mark' => '../outside/brand.png']]]);
    }

    public function testBrandNeedsTheContractPackage(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('brand.enabled needs nowo-tech/brand-contracts');
        (new NowoInstanceSettingsExtension(false))->load([['web_ui' => ['enabled' => false], 'brand' => ['enabled' => true, 'defaults' => ['name' => 'A']]]], new ContainerBuilder());
    }

    public function testPrependMapsTheFilesForTheBrandAndRecordsIt(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension($this->extension('doctrine'));
        $container->registerExtension(new NowoInstanceSettingsExtension());
        $container->loadFromExtension('nowo_instance_settings', ['brand' => ['enabled' => true]]);
        (new NowoInstanceSettingsExtension())->prepend($container);

        self::assertArrayHasKey(NowoInstanceSettingsExtension::FILES_MAPPING, $container->getExtensionConfig('doctrine')[0]['orm']['mappings']);
        self::assertTrue($container->getParameter(NowoInstanceSettingsExtension::FILES_MAPPED_PARAMETER));

        self::assertTrue(NowoInstanceSettingsExtension::filesEnabled([['brand' => ['enabled' => true]]]));
        self::assertFalse(NowoInstanceSettingsExtension::filesEnabled([['brand' => ['enabled' => true]], ['brand' => ['enabled' => false]]]));
        self::assertFalse(NowoInstanceSettingsExtension::filesEnabled([['brand' => ['enabled' => '%env(bool:B)%']]]));
    }

    public function testAFlagPrependedAfterThisExtensionFailsClearly(): void
    {
        // Audit IO-I10: prepend() saw no flag (the mapping is absent), then another extension enabled it.
        $container = new ContainerBuilder();
        $container->setParameter(NowoInstanceSettingsExtension::FILES_MAPPED_PARAMETER, false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the file table is not mapped');
        (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false], 'brand' => ['enabled' => true, 'defaults' => ['name' => 'A']]]], $container);
    }

    public function testMappedFilesPassTheCheck(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoInstanceSettingsExtension::FILES_MAPPED_PARAMETER, true);
        (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false], 'files' => ['enabled' => true]]], $container);

        $unmapped = new ContainerBuilder();
        $unmapped->setParameter(NowoInstanceSettingsExtension::FILES_MAPPED_PARAMETER, false);
        (new NowoInstanceSettingsExtension())->load([['web_ui' => ['enabled' => false]]], $unmapped);

        self::assertTrue($container->getParameter('nowo_instance_settings.files.enabled'));
        self::assertFalse($unmapped->getParameter('nowo_instance_settings.files.enabled'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $this->dir);
        $container->registerExtension($this->extension('security'));
        (new NowoInstanceSettingsExtension())->load([$config], $container);

        return $container;
    }

    private function extension(string $alias): Extension
    {
        return new class($alias) extends Extension {
            public function __construct(private readonly string $alias)
            {
            }

            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return $this->alias;
            }
        };
    }
}
