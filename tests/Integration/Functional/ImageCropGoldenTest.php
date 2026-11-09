<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\EmbedHostController;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_dir;
use function mkdir;
use function preg_replace;

/**
 * 1.8 (image crop): with the crop off (the default), the image pages are byte-identical to 1.7.1. The golden files
 * were rendered by 1.7.1 (`c0014bf`); only the per-session CSRF token values are masked.
 */
final class ImageCropGoldenTest extends KernelTestCase
{
    public function testTheSectionPageWithImageFieldsIsUnchanged(): void
    {
        $kernel = $this->bootKernel(['files' => ['enabled' => true]], [ImageFixtureSection::class => ImageFixtureSection::class]);
        $this->assertGolden('section-images.html', $this->page($kernel, '/admin/settings/sections/brand'));
    }

    public function testTheBrandSectionPageIsUnchanged(): void
    {
        $public = InstanceSettingsTestKernel::baseDir() . '/public-' . $this->name();
        if (!is_dir($public)) {
            mkdir($public, 0o777, true);
        }
        $kernel = $this->bootKernel(['brand' => ['enabled' => true, 'public_dir' => $public, 'defaults' => ['name' => 'Acme']]], fixtures: false);
        $this->assertGolden('section-brand.html', $this->page($kernel, '/admin/settings/sections/brand'));
    }

    public function testTheEmbeddedImageSectionIsUnchanged(): void
    {
        $kernel = $this->bootKernel(
            ['files' => ['enabled' => true]],
            [ImageFixtureSection::class => ImageFixtureSection::class, EmbedHostController::class => EmbedHostController::class],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->add('host_settings', '/host/{tenant}/settings/{section}')->controller([EmbedHostController::class, 'page'])->methods(['GET', 'POST']);
            },
        );
        $this->assertGolden('embed-images.html', $this->page($kernel, '/host/acme/settings/brand'));
    }

    private function page(InstanceSettingsTestKernel $kernel, string $url): string
    {
        $client = $this->client($kernel);
        $client->request('GET', $url);
        self::assertSame(200, $client->getResponse()->getStatusCode());

        return (string) preg_replace('/(name="[a-z_]+\[_token\]"[^>]*value=")[^"]*"/', '$1TOKEN"', (string) $client->getResponse()->getContent());
    }

    private function assertGolden(string $name, string $html): void
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/golden/' . $name;
        if (getenv('UPDATE_GOLDEN') === '1') {
            file_put_contents($path, $html);
        }
        self::assertSame((string) file_get_contents($path), $html);
    }
}
