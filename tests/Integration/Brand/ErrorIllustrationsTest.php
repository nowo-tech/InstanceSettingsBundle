<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Brand;

use LogicException;
use Nowo\InstanceSettingsBundle\Brand\BrandErrorIllustrationProvider;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Twig\Environment;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function tempnam;

/**
 * 1.13 (Beacon 1.147, mascot per situation): UiKit's error pages take the uploaded brand extra image error_<N>, else
 * the uploaded base image, else UiKit's own map; from the files on disk only (also with the database gone), in one
 * kept-alive kernel without the services resetter.
 */
final class ErrorIllustrationsTest extends KernelTestCase
{
    private const UI_KIT_MAP = ['illustrations' => [404 => 'illustrations/error-404', 'default' => 'illustrations/error-500']];

    public function testUploadedImagesThenUiKitMapWithoutTheDatabase(): void
    {
        $kernel = $this->kernel();
        self::assertInstanceOf(BrandErrorIllustrationProvider::class, $this->container($kernel)->get(BrandErrorIllustrationProvider::SERVICE_ID));

        // Nothing uploaded: UiKit's map (configured defaults never count).
        self::assertSame('map:illustrations/error-404', $this->illustration($kernel, 404));
        self::assertSame('map:illustrations/error-500', $this->illustration($kernel, 500));

        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[extra_mascot][file]', $this->tempFile(ImageFactory::png(300, 200)));
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        $mascot = (string) $this->settings($kernel)->getFile('brand.extra_mascot')?->url();
        self::assertStringStartsWith('/_settings-file/brand/extra_mascot/', $mascot);

        // The base image for every status without its own upload.
        self::assertSame('url:' . $mascot . ' 300x200', $this->illustration($kernel, 404));
        self::assertSame('url:' . $mascot . ' 300x200', $this->illustration($kernel, 503));

        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[extra_error_404][file]', $this->tempFile(ImageFactory::png(120, 80)));
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        $own = (string) $this->settings($kernel)->getFile('brand.extra_error_404')?->url();

        self::assertSame('url:' . $own . ' 120x80', $this->illustration($kernel, 404));
        self::assertSame('url:' . $mascot . ' 300x200', $this->illustration($kernel, 503));

        // The URL is the public file route.
        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', $own);
        self::assertSame(200, $anonymous->getResponse()->getStatusCode());

        // No database: the files on disk answer alone.
        $this->connection($kernel)->executeStatement('DROP TABLE instance_setting_value');
        self::assertSame('url:' . $own . ' 120x80', $this->illustration($kernel, 404));
        self::assertSame('url:' . $mascot . ' 300x200', $this->illustration($kernel, 500));
    }

    public function testRemovedUploadFallsBackInAKeptAliveKernel(): void
    {
        $kernel = $this->kernel();
        $admin  = $this->client($kernel);

        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[extra_error_404][file]', $this->tempFile(ImageFactory::png(64, 64)));
        $admin->submit($form);
        $own = (string) $this->settings($kernel)->getFile('brand.extra_error_404')?->url();

        for ($i = 0; $i < 3; ++$i) {
            self::assertSame('url:' . $own . ' 64x64', $this->illustration($kernel, 404));
            self::assertSame('map:illustrations/error-500', $this->illustration($kernel, 500), 'no base upload: the map');
        }

        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::tick($form, 'settings_section[extra_error_404][remove]');
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());

        self::assertSame('map:illustrations/error-404', $this->illustration($kernel, 404), 'cleared: nothing kept from the previous request');
    }

    public function testDatabaseStoreRegistersNothingAndRefusesABase(): void
    {
        $kernel = $this->bootKernel($this->config([], 'doctrine'), fixtures: false, extensions: ['nowo_ui_kit' => ['appearance' => ['enabled' => true]]]);
        self::assertFalse($this->container($kernel)->has(BrandErrorIllustrationProvider::SERVICE_ID));

        try {
            $this->bootKernel($this->config(['base' => 'mascot'], 'doctrine'), fixtures: false, extensions: ['nowo_ui_kit' => ['appearance' => ['enabled' => true]]]);
            self::fail('Expected the refusal.');
        } catch (LogicException $e) {
            self::assertStringContainsString('brand.error_illustrations needs files.store: filesystem', $e->getMessage());
        }
    }

    public function testTheBaseMustBeADeclaredExtraImage(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('brand.error_illustrations.base must be one of the brand.extra_images ids, got "hero"');

        $this->bootKernel($this->config(['base' => 'hero']), fixtures: false);
    }

    private function kernel(): InstanceSettingsTestKernel
    {
        return $this->bootKernel(
            $this->config(['base' => 'mascot']),
            fixtures: false,
            extensions: ['nowo_ui_kit' => [
                'appearance'  => ['enabled' => true],
                'error_pages' => self::UI_KIT_MAP + ['illustration_provider' => BrandErrorIllustrationProvider::SERVICE_ID, 'illustration_provider_budget_ms' => 20],
            ]],
        );
    }

    /**
     * @param array<string, mixed> $errorIllustrations
     *
     * @return array<string, mixed>
     */
    private function config(array $errorIllustrations, string $store = 'filesystem'): array
    {
        $public = InstanceSettingsTestKernel::baseDir() . '/public-' . $this->name();
        if (!is_dir($public . '/illustrations')) {
            mkdir($public . '/illustrations', 0o777, true);
        }
        file_put_contents($public . '/illustrations/error-404.png', ImageFactory::png(32, 32));

        return [
            'brand' => [
                'enabled'      => true,
                'public_dir'   => $public,
                'defaults'     => ['name' => 'Acme'],
                'extra_images' => [
                    'mascot'    => ['default' => 'illustrations/error-404.png'],
                    'error_404' => ['default' => 'illustrations/error-404.png'],
                ],
                'error_illustrations' => $errorIllustrations,
            ],
            'files' => ['store' => $store, 'directory' => InstanceSettingsTestKernel::baseDir() . '/files-' . $this->name()],
        ];
    }

    /**
     * What UiKit's error page gets for `$code`: `url:<src> <width>x<height>` (provider) or `map:<path>` (UiKit's map).
     */
    private function illustration(InstanceSettingsTestKernel $kernel, int $code): string
    {
        $twig = $this->container($kernel)->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->createTemplate(
            '{% set i = nowo_ui_error_page(code).illustration %}{{ i is null ? "none" : (i.isUrl ? "url:" ~ i.src ~ " " ~ i.width ~ "x" ~ i.height : "map:" ~ i.path) }}',
        )->render(['code' => $code]);
    }

    private function tempFile(string $bytes): string
    {
        $path = (string) tempnam(InstanceSettingsTestKernel::baseDir(), 'up');
        file_put_contents($path, $bytes);

        return $path;
    }
}
