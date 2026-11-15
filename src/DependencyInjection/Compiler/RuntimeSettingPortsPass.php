<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\Runtime\InstanceSettingsRuntimeSource;
use Nowo\InstanceSettingsBundle\Runtime\InstanceSettingsRuntimeWriter;
use Nowo\InstanceSettingsBundle\Runtime\RuntimeSettingAdminGuard;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.20 (`runtime_setting.enabled`): binds nowo-tech/runtime-setting-contract's ports to the instance settings only when
 * nothing else did (the host, NowoRuntimeSettingBundle's `source:` / `writer:`, RuntimeEnvBundle), both or neither
 * (audit QT-I-2: never reads from one backend and writes to another), and gives the settings the validators of the
 * registered runtime settings when NowoRuntimeSettingBundle is installed. Runs before the contract's default-ports pass.
 */
final class RuntimeSettingPortsPass implements CompilerPassInterface
{
    public const PRIORITY = 10;

    private const SOURCE = 'Nowo\\RuntimeSettingContract\\Contract\\RuntimeValueSourceInterface';

    private const WRITER = 'Nowo\\RuntimeSettingContract\\Contract\\RuntimeSettingWriterInterface';

    private const REGISTRY = 'Nowo\\RuntimeSettingContract\\Contract\\RuntimeSettingRegistry';

    /**
     * Binds the ports and the admin validation when the feature is on.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoInstanceSettingsExtension::RUNTIME_ENABLED_PARAMETER) || $container->getParameter(NowoInstanceSettingsExtension::RUNTIME_ENABLED_PARAMETER) !== true) {
            return;
        }

        if (!$container->has(self::SOURCE) && !$container->has(self::WRITER)) {
            $container->setAlias(self::SOURCE, InstanceSettingsRuntimeSource::class);
            $container->setAlias(self::WRITER, InstanceSettingsRuntimeWriter::class);
        }

        if ($container->has(self::REGISTRY) && $container->hasDefinition(InstanceSettings::class)) {
            $container->setDefinition(RuntimeSettingAdminGuard::class, new Definition(RuntimeSettingAdminGuard::class, [new Reference(self::REGISTRY)]));
            $container->getDefinition(InstanceSettings::class)->setArgument(12, new Reference(RuntimeSettingAdminGuard::class));
        }
    }
}
