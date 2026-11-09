<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Controller;

use LogicException;
use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\JsonUploadReader;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Support\FormFactorySupport;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\PageFixtures;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class SettingsControllerTest extends TestCase
{
    public function testDisabledPortabilityIsNotFoundEvenWhenRoutedByHand(): void
    {
        $registry   = PageFixtures::registry();
        $repository = new InMemorySettingValueRepository();
        $settings   = new InstanceSettings($repository, $registry);
        $controller = new SettingsController(
            $registry,
            $settings,
            new AccessGuard(new AllowAllInstanceSettingsAccessChecker()),
            PageFixtures::factory(),
            new ConfigPortability($registry, $settings, $repository),
            new JsonUploadReader(),
            false,
        );

        foreach ([static fn (): Response => $controller->portability(), static fn (): Response => $controller->export(new Request()), static fn (): Response => $controller->import(new Request())] as $call) {
            try {
                $call();
                self::fail('Expected NotFoundHttpException');
            } catch (NotFoundHttpException $e) {
                self::assertSame('Instance settings import / export is disabled.', $e->getMessage());
            }
        }
    }

    public function testAHandBuiltControllerStillServesTheSectionPage(): void
    {
        // 1.5 constructor signature (no embedder): section() builds the same embedder from its service locator.
        $controller = $this->handBuilt();
        $controller->setContainer($this->locator(new Environment(new ArrayLoader(['@NowoInstanceSettingsBundle/settings/section.html.twig' => '{{ section }}|{{ page.current }}']))));

        $response = $controller->section(new Request(), 'general');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('general|general', $response->getContent());

        $this->expectException(NotFoundHttpException::class);
        $controller->section(new Request(), 'nope');
    }

    public function testAHandBuiltControllerWithoutTwigIsALogicError(): void
    {
        $controller = $this->handBuilt();
        $controller->setContainer($this->locator(new stdClass()));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SettingsController::section() needs the form factory, Twig and the router');
        $controller->section(new Request(), 'general');
    }

    private function handBuilt(): SettingsController
    {
        $registry   = PageFixtures::registry();
        $repository = new InMemorySettingValueRepository();
        $settings   = new InstanceSettings($repository, $registry);

        return new SettingsController($registry, $settings, new AccessGuard(new AllowAllInstanceSettingsAccessChecker()), PageFixtures::factory(), new ConfigPortability($registry, $settings, $repository), new JsonUploadReader());
    }

    private function locator(object $twig): Container
    {
        $routes = new RouteCollection();
        $routes->add(InstanceSettingsRoutes::SECTION, new Route('/admin/settings/sections/{section}'));
        $container = new Container();
        $container->set('form.factory', FormFactorySupport::factory());
        $container->set('twig', $twig);
        $container->set('router', new UrlGenerator($routes, new RequestContext()));
        $container->set('request_stack', new RequestStack());

        return $container;
    }
}
