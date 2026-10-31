<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Routing;

use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\Routing\Loader\AttributeServicesLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Routing\RouterInterface;

use function is_string;

/**
 * REQ-SF-004 rule 7: a host whose routing is only `routing.controllers` (Symfony >= 7.3 default config/routes.yaml) gets
 * no bundle route; the routes exist only through the `nowo_instance_settings` loader import.
 */
final class RoutesOptInTest extends KernelTestCase
{
    public function testRoutingControllersDoesNotMountTheBundleRoutes(): void
    {
        if (!class_exists(AttributeServicesLoader::class)) {
            self::markTestSkipped('routing.controllers requires symfony/routing >= 7.3.');
        }

        $kernel = $this->bootKernel(routes: static function (RoutingConfigurator $routes): void {
            $routes->import('routing.controllers');
        });
        self::assertTrue($this->container($kernel)->has(SettingsController::class));

        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            self::assertStringStartsNotWith('nowo_instance_settings', $name);
            $controller = $route->getDefault('_controller');
            self::assertFalse(is_string($controller) && str_starts_with($controller, SettingsController::class), $name);
        }
    }

    public function testTheLoaderImportMountsThemUnderThePrefix(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['path_prefix' => '/ops/config']]);
        /** @var RouterInterface $router */
        $router = $kernel->getContainer()->get('router');

        self::assertSame('/ops/config/sections/{section}', $router->getRouteCollection()->get('nowo_instance_settings_section')?->getPath());
    }
}
