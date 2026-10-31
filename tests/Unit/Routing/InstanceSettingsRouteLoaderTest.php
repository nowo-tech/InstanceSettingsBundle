<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Routing;

use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRouteLoader;
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

    public function testDisabledUiAndPortability(): void
    {
        self::assertCount(0, (new InstanceSettingsRouteLoader(false, '/admin/settings'))->load('.'));

        $routes = (new InstanceSettingsRouteLoader(true, '/admin/settings', false))->load('.');
        self::assertSame(['nowo_instance_settings_index', 'nowo_instance_settings_section'], array_keys($routes->all()));
    }
}
