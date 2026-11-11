<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Brand;

use Nowo\InstanceSettingsBundle\Form\SettingsSectionType;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Symfony\Component\DomCrawler\Crawler;

use function file_put_contents;
use function is_dir;
use function mkdir;
use function substr_count;
use function sys_get_temp_dir;
use function tempnam;

/**
 * 1.12: the Brand images as one card per image field holding UiKit's image input box (stored image, else the default
 * with its badge; Replace / Remove / Undo; help under it), the host `extra_images` included, no separate preview list,
 * the name printed once; the no-JS path (plain file input + remove checkbox) uploads and removes.
 */
final class ImageInputCardsTest extends KernelTestCase
{
    public function testEveryImageFieldIsOneCardWithItsBox(): void
    {
        $admin   = $this->client($this->kernel());
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        $html = (string) $admin->getResponse()->getContent();

        self::assertCount(0, $crawler->filter('[data-testid="instance-settings-image-previews"]'));
        $cards = $crawler->filter('.' . SettingsSectionType::IMAGE_CARD_CLASS);
        self::assertSame(['logo_light', 'logo_dark', 'mark', 'app_icon', 'favicon', 'extra_mascot'], $cards->each(static fn (Crawler $card): string => substr((string) $card->filter('[data-nowo-ui-image-input-file]')->attr('id'), 17, -5)));
        foreach ($cards as $card) {
            $card = new Crawler($card);
            self::assertCount(1, $card->filter('[data-nowo-ui-image-input]'));
            self::assertCount(1, $card->filter('label'), 'one label per card (no duplicated name)');
            self::assertSame('images', $card->attr('data-nowo-instance-settings-group'));
            self::assertCount(0, $card->filter('[style]'));
        }
        self::assertSame(1, substr_count($html, '>Logo (light backgrounds)</label>'));

        // Help under the box, referenced by the file input and the buttons.
        $logo = $crawler->filter('#settings_section_logo_light');
        self::assertSame('settings_section_logo_light_file_help', $logo->filter('input[type="file"]')->attr('aria-describedby'));
        self::assertCount(1, $logo->filter('[data-nowo-ui-image-input] ~ #settings_section_logo_light_file_help'));

        // Host extra image with a config default: shown in its box with the Default badge.
        $mascot = $crawler->filter('#settings_section_extra_mascot [data-nowo-ui-image-input]');
        self::assertSame('default', $mascot->attr('data-nowo-ui-image-input-state'));
        self::assertMatchesRegularExpression('#^/brand/mascot\.png\?v=[0-9a-f]{12}$#', (string) $mascot->filter('img')->attr('src'));
        self::assertSame('Mascot', $mascot->filter('img')->attr('alt'));

        // UiKit's image-input script and the bundle stylesheet, once.
        self::assertCount(1, $crawler->filter('script[src$="/bundles/nowouikit/js/nowo-ui-image-input.js"]'));
        self::assertCount(1, $crawler->filter('link[href$="/bundles/nowoinstancesettings/css/nowo-instance-settings.css"]'));
        self::assertCount(0, $crawler->filter('script[src$="nowo-instance-settings-color.js"]'));
    }

    public function testTheNoJsPathUploadsAndRemoves(): void
    {
        $kernel  = $this->kernel();
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');

        $form = $crawler->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[extra_mascot][file]', $this->png());
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        $file = $this->settings($kernel)->getFile('brand.extra_mascot');
        self::assertNotNull($file);

        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $box     = $crawler->filter('#settings_section_extra_mascot [data-nowo-ui-image-input]');
        self::assertSame('stored', $box->attr('data-nowo-ui-image-input-state'));
        self::assertSame($file->url(), $box->filter('img')->attr('src'));
        self::assertSame('/brand/mascot.png', substr((string) $box->attr('data-nowo-ui-image-input-default'), 0, 17), 'the default is kept for Remove');

        $form = $crawler->filter('form[name="settings_section"]')->form();
        self::tick($form, 'settings_section[extra_mascot][remove]');
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertNull($this->settings($kernel)->getFile('brand.extra_mascot'));
    }

    private function kernel(): InstanceSettingsTestKernel
    {
        $public = InstanceSettingsTestKernel::baseDir() . '/public-' . $this->name();
        if (!is_dir($public . '/brand')) {
            mkdir($public . '/brand', 0o777, true);
        }
        file_put_contents($public . '/brand/mascot.png', ImageFactory::png(64, 64));

        return $this->bootKernel(['brand' => [
            'enabled'      => true,
            'public_dir'   => $public,
            'defaults'     => ['name' => 'Acme'],
            'extra_images' => ['mascot' => ['label' => 'Mascot', 'max_width' => 512, 'max_height' => 512, 'square' => true, 'default' => 'brand/mascot.png']],
        ]], fixtures: false);
    }

    private function png(): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'is-card');
        file_put_contents($path, ImageFactory::png(128, 128));

        return $path;
    }
}
