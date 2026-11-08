<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Twig;

use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingFileStore;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Twig\InstanceSettingsTwigExtension;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ImageTwigTest extends TestCase
{
    public function testInstanceSettingFile(): void
    {
        $settings = new InstanceSettings(new InMemorySettingValueRepository(), new SettingsSectionRegistry([new ImageFixtureSection()]), fileStore: new InMemorySettingFileStore());
        $twig     = new Environment(new ArrayLoader(['t' => '{{ instance_setting_file("brand.logo") is null ? "none" : instance_setting_file("brand.logo").mimeType }}|{{ instance_setting("brand.logo") is null ? "null" : "leak" }}']));
        $twig->addExtension(new InstanceSettingsTwigExtension($settings));

        self::assertSame('none|null', $twig->render('t'));
        $settings->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::webp())]);
        self::assertSame('image/webp|null', $twig->render('t'));
    }
}
