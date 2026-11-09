<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Brand;

use InvalidArgumentException;
use Nowo\Brand\Contract\BrandAsset;
use Nowo\Brand\Contract\BrandAssetOrigin;
use Nowo\Brand\Contract\BrandAssetReadException;
use Nowo\InstanceSettingsBundle\Brand\BrandDefaults;
use Nowo\InstanceSettingsBundle\Brand\BrandTwigExtension;
use Nowo\InstanceSettingsBundle\Brand\DefaultAsset;
use Nowo\InstanceSettingsBundle\Brand\InstanceBrandAssetReader;
use Nowo\InstanceSettingsBundle\Brand\TranslatableBrandText;
use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Tests\Support\BrandFixture;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Translation\IdentityTranslator;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

use function file_put_contents;
use function hash;
use function mkdir;
use function str_repeat;
use function symlink;
use function sys_get_temp_dir;
use function uniqid;

/**
 * Config default assets, the asset reader, the translatable default text and `nowo_brand()`.
 */
final class BrandAssetsTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-brand-assets-' . uniqid();
        mkdir($this->dir . '/public/brand', 0o777, true);
        file_put_contents($this->dir . '/public/brand/mark.png', ImageFactory::png(64, 48));
        file_put_contents($this->dir . '/public/brand/logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        file_put_contents($this->dir . '/public/brand/broken.png', 'not an image');
        file_put_contents($this->dir . '/outside.png', ImageFactory::png(8, 8));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testRasterAndSvgDefaults(): void
    {
        $mark = DefaultAsset::fromFile('mark', 'brand/mark.png', $this->dir . '/public');
        self::assertSame(['/brand/mark.png?v=' . substr($mark->sha256, 0, 12), 'image/png', 64, 48], [$mark->url, $mark->mimeType, $mark->width, $mark->height]);
        self::assertSame(hash('sha256', (string) file_get_contents($this->dir . '/public/brand/mark.png')), $mark->sha256);
        self::assertEquals($mark, DefaultAsset::fromArray($mark->toArray()));
        $asset = $mark->toBrandAsset();
        self::assertSame(BrandAssetOrigin::ConfigDefault, $asset->origin);

        $svg = DefaultAsset::fromFile('logo_light', 'brand/logo.svg', $this->dir . '/public');
        self::assertSame(['image/svg+xml', null, null], [$svg->mimeType, $svg->width, $svg->height]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafePaths(): iterable
    {
        yield 'absolute' => ['/brand/mark.png'];
        yield 'traversal' => ['brand/../../outside.png'];
        yield 'dot segment' => ['brand/./mark.png'];
        yield 'backslash' => ['brand\\mark.png'];
        yield 'scheme' => ['https://cdn.example/mark.png'];
        yield 'space' => ['brand/my mark.png'];
        yield 'not an image type' => ['brand/mark.txt'];
        yield 'missing' => ['brand/missing.png'];
        yield 'directory' => ['brand.png'];
        yield 'broken raster' => ['brand/broken.png'];
    }

    #[DataProvider('unsafePaths')]
    public function testUnsafeOrInvalidPathsAreRefused(string $path): void
    {
        if ($path === 'brand.png') {
            mkdir($this->dir . '/public/brand.png');
        }
        $this->expectException(InvalidArgumentException::class);
        DefaultAsset::fromFile('mark', $path, $this->dir . '/public');
    }

    public function testSymlinksOutOfThePublicDirectoryAreRefused(): void
    {
        symlink($this->dir . '/outside.png', $this->dir . '/public/brand/link.png');
        $this->expectException(InvalidArgumentException::class);
        DefaultAsset::fromFile('mark', 'brand/link.png', $this->dir . '/public');
    }

    public function testAMissingPublicDirectoryIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DefaultAsset::fromFile('mark', 'brand/mark.png', $this->dir . '/nope');
    }

    public function testDefaultsAndTranslatableText(): void
    {
        $defaults = new BrandDefaults('Acme', eyebrowText: 'Hi');
        self::assertSame('Hi', $defaults->eyebrow());
        self::assertNull($defaults->asset('mark'));

        $keyed = new BrandDefaults('Acme', eyebrowKey: 'brand.eyebrow', eyebrowDomain: 'app');
        $text  = $keyed->eyebrow();
        self::assertInstanceOf(TranslatableBrandText::class, $text);
        self::assertSame('app:brand.eyebrow', (string) $text);
        self::assertSame('brand.eyebrow', $text->trans(new IdentityTranslator()));
        self::assertNull((new BrandDefaults('Acme'))->eyebrow());
    }

    public function testReaderReadsUploadsVariantsAndDefaults(): void
    {
        $mark    = DefaultAsset::fromFile('mark', 'brand/mark.png', $this->dir . '/public');
        $fixture = new BrandFixture(new BrandDefaults('Acme', assets: ['mark' => $mark->toArray()]));
        $reader  = new InstanceBrandAssetReader($fixture->defaults, $fixture->store);
        $fixture->settings->save('brand', ['favicon' => ImageUpload::fromBytes(ImageFactory::png(64, 64))]);
        $favicon = $fixture->provider->getBrand()->assets['favicon'];

        self::assertStringStartsWith("\x89PNG", $reader->read($favicon));
        self::assertStringStartsWith("\x89PNG", $reader->read($favicon, 16));
        self::assertSame(file_get_contents($this->dir . '/public/brand/mark.png'), $reader->read($mark->toBrandAsset()));

        foreach ([
            'undeclared variant'      => [$favicon, 48],
            'variant of a default'    => [$mark->toBrandAsset(), 16],
            'forged hash'             => [new BrandAsset('favicon', '/x.png', 'image/png', 1, 1, str_repeat('a', 64), BrandAssetOrigin::Uploaded), null],
            'other field'             => [new BrandAsset('mark', '/x.png', 'image/png', 1, 1, $favicon->sha256, BrandAssetOrigin::Uploaded), null],
            'unconfigured default'    => [new BrandAsset('logo_dark', '/x.png', 'image/png', 1, 1, $mark->sha256, BrandAssetOrigin::ConfigDefault), null],
            'default of another file' => [new BrandAsset('mark', '/x.png', 'image/png', 1, 1, str_repeat('b', 64), BrandAssetOrigin::ConfigDefault), null],
        ] as $case => [$asset, $variant]) {
            try {
                $reader->read($asset, $variant);
                self::fail($case);
            } catch (BrandAssetReadException $e) {
                self::assertStringNotContainsString($this->dir, $e->getMessage(), $case);
            }
        }

        // No store: uploads are unreadable.
        $this->expectException(BrandAssetReadException::class);
        (new InstanceBrandAssetReader($fixture->defaults))->read($favicon);
    }

    public function testReaderRefusesADefaultChangedSinceCompile(): void
    {
        $mark   = DefaultAsset::fromFile('mark', 'brand/mark.png', $this->dir . '/public');
        $reader = new InstanceBrandAssetReader(new BrandDefaults('Acme', assets: ['mark' => $mark->toArray()]));
        file_put_contents($this->dir . '/public/brand/mark.png', ImageFactory::png(10, 10));

        $this->expectException(BrandAssetReadException::class);
        $this->expectExceptionMessage('Brand asset "mark" (' . substr($mark->sha256, 0, 12) . ') is not available.');
        $reader->read($mark->toBrandAsset());
    }

    public function testNowoBrandTwigFunction(): void
    {
        $fixture = new BrandFixture(new BrandDefaults('Acme <b>'));
        $twig    = new Environment(new ArrayLoader(['t' => '{{ nowo_brand().name }}|{{ nowo_brand().isDefault ? "d" : "s" }}']));
        $twig->addExtension(new BrandTwigExtension($fixture->provider));

        self::assertSame('Acme &lt;b&gt;|d', $twig->render('t'));
    }
}
