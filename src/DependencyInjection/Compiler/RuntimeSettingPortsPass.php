<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\Repository\RepositorySettingRowWriter;
use Nowo\InstanceSettingsBundle\Runtime\InstanceSettingsRuntimeSource;
use Nowo\InstanceSettingsBundle\Runtime\InstanceSettingsRuntimeWriter;
use Nowo\InstanceSettingsBundle\Runtime\RuntimeSettingAdminGuard;
use Nowo\InstanceSettingsBundle\Seed\FallbackSeeder;
use Nowo\InstanceSettingsBundle\Seed\SeedFileSeeder;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettings;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

use function sprintf;

/**
 * 1.20 (`runtime_setting.enabled`): binds nowo-tech/runtime-setting-contract's ports to the instance settings only when
 * nothing else did (the host, NowoRuntimeSettingBundle's `source:` / `writer:`), both or neither (audit QT-I-2: never
 * reads from one backend and writes to another), and gives every save path of the settings (the store, the seed file,
 * the fallback seeder, the raw row writer; audits QS-M-2, QZ-L-2) the validators of the registered runtime settings when
 * NowoRuntimeSettingBundle is installed.
 *
 * Runs before RuntimeEnvBundle's ports pass and the contract's default-ports pass (priority {@see self::PRIORITY}), so a
 * binding seen here is the host's. With RuntimeEnvBundle's `runtime_setting.enabled` on as well, the host must bind both
 * ports itself: the build fails otherwise (audit QZ-L-6), since one adapter alone serves only one id form.
 */
final class RuntimeSettingPortsPass implements CompilerPassInterface
{
    public const PRIORITY = 11;

    /** RuntimeEnvBundle's flag (1.6). */
    public const RUNTIME_ENV_ENABLED_PARAMETER = 'nowo_runtime_env.runtime_setting.enabled';

    private const SOURCE = 'Nowo\\RuntimeSettingContract\\Contract\\RuntimeValueSourceInterface';

    private const WRITER = 'Nowo\\RuntimeSettingContract\\Contract\\RuntimeSettingWriterInterface';

    private const REGISTRY = 'Nowo\\RuntimeSettingContract\\Contract\\RuntimeSettingRegistry';

    /** Service => index of its optional RuntimeSettingAdminGuard argument. */
    private const GUARDED = [
        InstanceSettings::class           => 12,
        SeedFileSeeder::class             => 13,
        FallbackSeeder::class             => 10,
        RepositorySettingRowWriter::class => 3,
    ];

    /**
     * Binds the ports and the save-path validation when the feature is on.
     *
     * @throws LogicException when RuntimeEnvBundle's runtime settings are on too and the host bound neither or one port
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$this->enabled($container, NowoInstanceSettingsExtension::RUNTIME_ENABLED_PARAMETER)) {
            return;
        }

        $source = $container->has(self::SOURCE);
        $writer = $container->has(self::WRITER);
        if ($this->enabled($container, self::RUNTIME_ENV_ENABLED_PARAMETER) && (!$source || !$writer)) {
            throw new LogicException(sprintf('NowoInstanceSettingsBundle and NowoRuntimeEnvBundle both enable runtime_setting, and the runtime-setting ports are not both bound by the host: the bundle registered first would bind them and the other id form would silently read nothing. Bind %s and %s yourself, for example with nowo_runtime_setting: { source: <id>, writer: <id> }: %s and %s serve "section.field" ids; Nowo\\RuntimeEnvBundle\\RuntimeSetting\\RuntimeEnvSettingStore (for both) serves environment-variable names; a routing source and writer of your own can serve both. Or turn runtime_setting.enabled off in one of the bundles.', self::SOURCE, self::WRITER, InstanceSettingsRuntimeSource::class, InstanceSettingsRuntimeWriter::class));
        }
        if (!$source && !$writer) {
            $container->setAlias(self::SOURCE, InstanceSettingsRuntimeSource::class);
            $container->setAlias(self::WRITER, InstanceSettingsRuntimeWriter::class);
        }

        if (!$container->has(self::REGISTRY)) {
            return;
        }
        $container->setDefinition(RuntimeSettingAdminGuard::class, new Definition(RuntimeSettingAdminGuard::class, [new Reference(self::REGISTRY)]));
        foreach (self::GUARDED as $service => $index) {
            if ($container->hasDefinition($service)) {
                $container->getDefinition($service)->setArgument($index, new Reference(RuntimeSettingAdminGuard::class));
            }
        }
    }

    private function enabled(ContainerBuilder $container, string $parameter): bool
    {
        return $container->hasParameter($parameter) && $container->getParameter($parameter) === true;
    }
}
