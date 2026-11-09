<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\CropEdgeFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\EmbedHostController;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\UiKitBundle\Form\ImageCrop;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function file_put_contents;
use function is_dir;
use function json_decode;
use function mkdir;
use function substr_count;
use function tempnam;

use const JSON_THROW_ON_ERROR;

/**
 * 1.8 (owner decision D-20261109-09): `files.crop` puts UiKit's crop step on every image field of the section page,
 * the brand section and the IS-1 embed, loads the image-crop script once, and leaves the server unchanged: crops in
 * WebP, PNG or JPEG are all accepted and re-encoded as before.
 */
final class ImageCropTest extends KernelTestCase
{
    private const SCRIPT = '/bundles/nowouikit/js/nowo-ui-image-crop.js';

    public function testTheSectionPageCropsEveryImageFieldAndLoadsTheScriptOnce(): void
    {
        $admin   = $this->client($this->cropKernel());
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        self::assertSame(200, $admin->getResponse()->getStatusCode());

        $logo = $crawler->filter('input[name="settings_section[logo][file]"]');
        self::assertSame('', $logo->attr(ImageCrop::ATTRIBUTE));
        self::assertSame(['free', '800', 'auto', '996147'], [$logo->attr(ImageCrop::ATTRIBUTE . '-aspect'), $logo->attr(ImageCrop::ATTRIBUTE . '-max'), $logo->attr(ImageCrop::ATTRIBUTE . '-format'), $logo->attr(ImageCrop::ATTRIBUTE . '-max-bytes')]);
        self::assertSame('image/png,image/webp,image/jpeg', $logo->attr('accept'));
        $icon = $crawler->filter('input[name="settings_section[icon][file]"]');
        self::assertSame(['1', '32'], [$icon->attr(ImageCrop::ATTRIBUTE . '-aspect'), $icon->attr(ImageCrop::ATTRIBUTE . '-min')]);
        $scan = $crawler->filter('input[name="settings_section[scan][file]"]');
        self::assertSame(['jpeg', 'image/jpeg'], [$scan->attr(ImageCrop::ATTRIBUTE . '-format'), $scan->attr('accept')]);
        self::assertSame('Crop the image', json_decode((string) $logo->attr(ImageCrop::ATTRIBUTE . '-labels'), true, 512, JSON_THROW_ON_ERROR)['title']);

        self::assertSame(1, substr_count((string) $admin->getResponse()->getContent(), 'src="' . self::SCRIPT . '"'));
    }

    public function testCropsInEveryFormatAreAcceptedAndReEncoded(): void
    {
        $kernel = $this->cropKernel();
        $admin  = $this->client($kernel);
        foreach (['image/webp' => ImageFactory::webp(300, 100), 'image/png' => ImageFactory::png(300, 100), 'image/jpeg' => ImageFactory::jpeg(300, 100)] as $type => $bytes) {
            $form = $admin->request('GET', '/admin/settings/sections/brand')->filter('form[name="settings_section"]')->form();
            self::upload($form, 'settings_section[logo][file]', $this->tempFile($bytes));
            $admin->submit($form);
            self::assertSame(302, $admin->getResponse()->getStatusCode(), $type);
            $this->settings($kernel)->reset();
            self::assertSame($type, $this->settings($kernel)->getFile('brand.logo')?->mimeType);
        }

        // The server stays the source of truth: a crop over the field's dimensions is still refused.
        $form = $admin->request('GET', '/admin/settings/sections/brand')->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[logo][file]', $this->tempFile(ImageFactory::png(900, 900)));
        $admin->submit($form);
        self::assertSame(422, $admin->getResponse()->getStatusCode());
    }

    public function testTheEmbedCropsAndLoadsTheScript(): void
    {
        $kernel = $this->bootKernel(
            ['files' => ['enabled' => true, 'crop' => ['shape' => 'circle']]],
            [ImageFixtureSection::class => ImageFixtureSection::class, EmbedHostController::class => EmbedHostController::class],
            routes: static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_instance_settings');
                $routes->add('host_settings', '/host/{tenant}/settings/{section}')->controller([EmbedHostController::class, 'page'])->methods(['GET', 'POST']);
            },
        );
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/host/acme/settings/brand');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertSame('circle', $crawler->filter('input[name="settings_section[logo][file]"]')->attr(ImageCrop::ATTRIBUTE . '-shape'));
        self::assertStringContainsString('src="' . self::SCRIPT . '"', (string) $client->getResponse()->getContent());

        // A section without image fields loads nothing.
        $client->request('GET', '/host/acme/settings/general');
        self::assertStringNotContainsString(self::SCRIPT, (string) $client->getResponse()->getContent());
    }

    public function testTheBrandFieldsGetTheirDerivedCrops(): void
    {
        $public = InstanceSettingsTestKernel::baseDir() . '/public-' . $this->name();
        if (!is_dir($public)) {
            mkdir($public, 0o777, true);
        }
        $admin   = $this->client($this->bootKernel(['files' => ['crop' => true], 'brand' => ['enabled' => true, 'public_dir' => $public, 'defaults' => ['name' => 'Acme']]], fixtures: false));
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        self::assertSame(200, $admin->getResponse()->getStatusCode());

        $attr = static fn (Crawler $input): array => [$input->attr(ImageCrop::ATTRIBUTE . '-aspect'), $input->attr(ImageCrop::ATTRIBUTE . '-min') ?? '1', $input->attr(ImageCrop::ATTRIBUTE . '-max')];
        self::assertSame(['free', '1', '800'], $attr($crawler->filter('input[name="settings_section[logo_light][file]"]')));
        self::assertSame(['1', '64', '1024'], $attr($crawler->filter('input[name="settings_section[mark][file]"]')));
        self::assertSame(['1', '512', '2048'], $attr($crawler->filter('input[name="settings_section[app_icon][file]"]')));
        self::assertSame(['1', '32', '2048'], $attr($crawler->filter('input[name="settings_section[favicon][file]"]')));
    }

    public function testFieldsTheDerivedCropCannotFitAreShownWithoutIt(): void
    {
        // Audit JP-M1: files.crop never takes a page with valid field rules down.
        $admin   = $this->client($this->bootKernel(['files' => ['enabled' => true, 'crop' => true]], [CropEdgeFixtureSection::class => CropEdgeFixtureSection::class]));
        $crawler = $admin->request('GET', '/admin/settings/sections/edges');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertNull($crawler->filter('input[name="settings_section[banner][file]"]')->attr(ImageCrop::ATTRIBUTE));
        self::assertNull($crawler->filter('input[name="settings_section[pixel][file]"]')->attr(ImageCrop::ATTRIBUTE));
        self::assertSame('', $crawler->filter('input[name="settings_section[logo][file]"]')->attr(ImageCrop::ATTRIBUTE));
    }

    public function testTinyBrandExtraImagesAreShownWithoutTheCrop(): void
    {
        $public = InstanceSettingsTestKernel::baseDir() . '/public-' . $this->name();
        if (!is_dir($public)) {
            mkdir($public, 0o777, true);
        }
        $admin = $this->client($this->bootKernel(['files' => ['crop' => ['shape' => 'circle']], 'brand' => [
            'enabled'      => true,
            'public_dir'   => $public,
            'defaults'     => ['name' => 'Acme'],
            'extra_images' => ['dot' => ['max_width' => 10, 'max_height' => 10], 'mascot' => ['max_width' => 512, 'max_height' => 512, 'square' => true]],
        ]], fixtures: false));
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertNull($crawler->filter('input[name="settings_section[extra_dot][file]"]')->attr(ImageCrop::ATTRIBUTE));
        self::assertSame('circle', $crawler->filter('input[name="settings_section[extra_mascot][file]"]')->attr(ImageCrop::ATTRIBUTE . '-shape'));
    }

    public function testARefusedBundleDefaultFailsTheBoot(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->bootKernel(['files' => ['enabled' => true, 'crop' => ['labels' => ['nope' => 'x']]]]);
    }

    private function cropKernel(): InstanceSettingsTestKernel
    {
        return $this->bootKernel(['files' => ['enabled' => true, 'crop' => true]], [ImageFixtureSection::class => ImageFixtureSection::class]);
    }

    private function tempFile(string $bytes): string
    {
        $path = (string) tempnam(InstanceSettingsTestKernel::baseDir(), 'up');
        file_put_contents($path, $bytes);

        return $path;
    }
}
