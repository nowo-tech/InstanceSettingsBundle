<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Controller;

use Nowo\InstanceSettingsBundle\Controller\SettingsController;
use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Portability\JsonUploadReader;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\AllowAllInstanceSettingsAccessChecker;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use Nowo\InstanceSettingsBundle\Tests\Support\PageFixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
}
