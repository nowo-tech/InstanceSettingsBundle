<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit;

use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\SecretEncryptionPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\SettingsSectionPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class NowoInstanceSettingsBundleTest extends TestCase
{
    public function testBuildRegistersPassesAndExtension(): void
    {
        $bundle    = new NowoInstanceSettingsBundle();
        $container = new ContainerBuilder();
        $bundle->build($container);

        $classes = array_map(static fn (object $pass): string => $pass::class, $container->getCompilerPassConfig()->getPasses());
        foreach ([TwigPathsPass::class, CsrfProtectionPass::class, UnauthenticatedAccessPass::class, SecretEncryptionPass::class, SettingsSectionPass::class] as $pass) {
            self::assertContains($pass, $classes);
        }

        $extension = $bundle->getContainerExtension();
        self::assertInstanceOf(NowoInstanceSettingsExtension::class, $extension);
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
