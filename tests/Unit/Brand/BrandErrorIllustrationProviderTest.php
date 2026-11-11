<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Brand;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Brand\BrandErrorIllustrationProvider;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\SettingFileUrls;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\UiKitBundle\View\ErrorIllustration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function bin2hex;
use function file_put_contents;
use function hash;
use function random_bytes;
use function substr;
use function sys_get_temp_dir;
use function unlink;

/**
 * 1.13: error page illustrations from the uploaded brand extra images, resolved from the files on disk only.
 */
final class BrandErrorIllustrationProviderTest extends TestCase
{
    private string $dir;

    private FilesystemSettingFileStore $store;

    protected function setUp(): void
    {
        $this->dir   = sys_get_temp_dir() . '/nowo-is-err-' . bin2hex(random_bytes(6));
        $this->store = new FilesystemSettingFileStore($this->dir . '/files');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testPerCodeThenBaseThenNull(): void
    {
        $provider = new BrandErrorIllustrationProvider($this->store, $this->urls(), BrandErrorIllustrationProvider::codeFields(['mascot', 'error_404', 'error_503']), 'extra_mascot');

        self::assertNull($provider->getIllustration(400), 'nothing uploaded: UiKit map');

        $mascot = $this->save('extra_mascot', ImageFactory::png(300, 200));
        $own    = $this->save('extra_error_404', ImageFactory::jpeg(120, 90));

        $illustration = $provider->getIllustration(404);
        self::assertInstanceOf(ErrorIllustration::class, $illustration);
        self::assertTrue($illustration->isUrl());
        self::assertSame('/_settings-file/brand/extra_error_404/' . substr($own, 0, 12) . '.jpg', $illustration->src);
        self::assertSame([120, 90, false, null, null], [$illustration->width, $illustration->height, $illustration->webp, $illustration->webpSrcset, $illustration->srcset]);

        foreach ([503, 500, 0] as $code) {
            $base = $provider->getIllustration($code);
            self::assertNotNull($base, 'no own upload: the base image (' . $code . ')');
            self::assertSame('/_settings-file/brand/extra_mascot/' . substr($mascot, 0, 12) . '.png', $base->src);
            self::assertSame([300, 200], [$base->width, $base->height]);
        }
    }

    public function testWithoutBaseOrPerCode(): void
    {
        $this->save('extra_error_404', ImageFactory::png(40, 40));
        $this->save('extra_mascot', ImageFactory::png(40, 40));

        $codesOnly = new BrandErrorIllustrationProvider($this->store, $this->urls(), BrandErrorIllustrationProvider::codeFields(['error_404']));
        self::assertNotNull($codesOnly->getIllustration(404));
        self::assertNull($codesOnly->getIllustration(500), 'no base: UiKit map');

        $baseOnly = new BrandErrorIllustrationProvider($this->store, $this->urls(), [], 'extra_mascot');
        self::assertStringContainsString('/extra_mascot/', (string) $baseOnly->getIllustration(404)?->src, 'per_code off: the base image');

        self::assertNull((new BrandErrorIllustrationProvider($this->store, $this->urls()))->getIllustration(404));
    }

    public function testRouteNotMountedAnswersNull(): void
    {
        $this->save('extra_mascot', ImageFactory::png(40, 40));
        $provider = new BrandErrorIllustrationProvider($this->store, new SettingFileUrls(), [], 'extra_mascot');

        self::assertNull($provider->getIllustration(404));
    }

    public function testAFileWithoutMetadataIsReadFromDiskAndARemovedFileIsGone(): void
    {
        $this->save('extra_mascot', ImageFactory::png(40, 40));
        $root  = $this->dir . '/files/brand';
        $bytes = ImageFactory::png(64, 32);
        // A committed file replaced by a `git pull`: the metadata no longer matches, the file itself is read.
        file_put_contents($root . '/extra_mascot.png', $bytes);
        $provider = new BrandErrorIllustrationProvider($this->store, $this->urls(), [], 'extra_mascot');

        $illustration = $provider->getIllustration(500);
        self::assertSame('/_settings-file/brand/extra_mascot/' . substr(hash('sha256', $bytes), 0, 12) . '.png', $illustration?->src);
        self::assertSame([64, 32], [$illustration->width, $illustration->height]);

        unlink($root . '/extra_mascot.png');
        self::assertNull($provider->getIllustration(500), 'cleared: UiKit map');
    }

    public function testCodeFieldsKeepsOnlyHttpErrorStatuses(): void
    {
        self::assertSame(
            [400 => 'extra_error_400', 503 => 'extra_error_503', 599 => 'extra_error_599'],
            BrandErrorIllustrationProvider::codeFields(['error_400', 'mascot', 'error_503', 'error_399', 'error_600', 'error_4040', 'error_x04', 'error_599', 7]),
        );
    }

    private function save(string $field, string $bytes): string
    {
        $image = (new ImageProcessor())->process(new ImageSpec(), $bytes);
        $this->store->save('brand', $field, $image, new DateTimeImmutable('2026-11-11 10:00:00'));

        return $image->sha256();
    }

    private function urls(): SettingFileUrls
    {
        $routes = new RouteCollection();
        $routes->add(InstanceSettingsRoutes::FILE, new Route('/_settings-file/{section}/{field}/{file}.{ext}'));

        return new SettingFileUrls(new UrlGenerator($routes, new RequestContext()));
    }
}
