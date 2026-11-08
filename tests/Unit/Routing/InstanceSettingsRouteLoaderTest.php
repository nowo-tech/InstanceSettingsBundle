<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Routing;

use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\File\SettingFileController;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRouteLoader;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class InstanceSettingsRouteLoaderTest extends TestCase
{
    public function testLoadsPrefixedRoutes(): void
    {
        $loader = new InstanceSettingsRouteLoader(true, '/ops/settings/');
        self::assertTrue($loader->supports('.', 'nowo_instance_settings'));
        self::assertFalse($loader->supports('.', 'attribute'));

        $routes = $loader->load('.', 'nowo_instance_settings');
        self::assertSame('/ops/settings', $routes->get('nowo_instance_settings_index')?->getPath());
        $section = $routes->get('nowo_instance_settings_section');
        self::assertNotNull($section);
        self::assertSame('/ops/settings/sections/{section}', $section->getPath());
        self::assertSame(['GET', 'POST'], $section->getMethods());
        self::assertSame(SettingsController::class . '::section', $section->getDefault('_controller'));
        self::assertSame(['POST'], $routes->get('nowo_instance_settings_import')?->getMethods());
        self::assertCount(5, $routes);

        $this->expectException(RuntimeException::class);
        $loader->load('.', 'nowo_instance_settings');
    }

    public function testFileRouteOnlyWithImageFields(): void
    {
        $without = (new InstanceSettingsRouteLoader(true, '/admin/settings', true, null, new SettingsSectionRegistry([new GeneralFixtureSection()])))->load('.');
        self::assertCount(5, $without);
        self::assertNull($without->get(InstanceSettingsRoutes::FILE));
        self::assertNotEmpty($without->getResources(), 'section classes are tracked');

        $registry = new SettingsSectionRegistry([new ImageFixtureSection()]);
        $with     = (new InstanceSettingsRouteLoader(false, '/admin/settings', true, null, $registry, '/media/'))->load('.');
        $route    = $with->get(InstanceSettingsRoutes::FILE);
        self::assertNotNull($route, 'served even with the admin UI off');
        self::assertCount(1, $with);
        self::assertSame('/media/{section}/{field}/{file}.{ext}', $route->getPath());
        self::assertSame(['GET', 'HEAD'], $route->getMethods());
        self::assertSame(SettingFileController::class, $route->getDefault('_controller'));
        self::assertSame('png|jpg|webp', $route->getRequirement('ext'));
    }

    public function testDisabledUiAndPortability(): void
    {
        self::assertCount(0, (new InstanceSettingsRouteLoader(false, '/admin/settings'))->load('.'));

        $routes = (new InstanceSettingsRouteLoader(true, '/admin/settings', false))->load('.');
        self::assertSame(['nowo_instance_settings_index', 'nowo_instance_settings_section'], array_keys($routes->all()));
    }
}
