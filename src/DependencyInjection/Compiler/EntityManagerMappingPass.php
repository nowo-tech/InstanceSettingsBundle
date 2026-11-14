<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use Doctrine\Bundle\DoctrineBundle\DependencyInjection\Compiler\DoctrineOrmMappingsPass;
use Nowo\InstanceSettingsBundle\DependencyInjection\NowoInstanceSettingsExtension;
use Nowo\InstanceSettingsBundle\Doctrine\ExcludedEntityNamespaceDriver;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

use function array_keys;
use function array_unshift;
use function implode;
use function is_array;
use function is_string;
use function sprintf;
use function str_starts_with;

/**
 * Keeps the bundle entities on `doctrine.entity_manager` only (1.11, architecture bar rule 6).
 *
 * With a dedicated manager the bundle maps its entities on that manager ({@see DoctrineOrmMappingsPass},
 * registered by the bundle class). DoctrineBundle's `auto_mapping` (the Flex default on the default manager) would map
 * them a second time, and `getManagerForClass()` would then return the default manager. This pass removes the bundle
 * namespaces from the mapping driver chain of every other manager and registers an
 * {@see ExcludedEntityNamespaceDriver} for each of them there instead (first in the chain), so a default driver of that
 * chain cannot claim the entities either (DoctrineBundle 3.4 `auto_discover_entities`); only the public `addDriver()`
 * of the `doctrine.orm.<manager>_metadata_driver` chain is used. It fails the build when the configured manager does
 * not exist or does not map {@see self::ENTITY_NAMESPACE}. Without `doctrine.entity_manager` it does nothing (the
 * mapping is prepended to the default manager, as before 1.11).
 */
final class EntityManagerMappingPass implements CompilerPassInterface
{
    /** Namespace of the settings entity. */
    public const ENTITY_NAMESPACE = 'Nowo\\InstanceSettingsBundle\\Entity';

    /** Namespace of the image file entity (mapped with `files.enabled` only). */
    public const FILE_ENTITY_NAMESPACE = 'Nowo\\InstanceSettingsBundle\\FileEntity';

    /** Namespace of the expiry entity (1.17, mapped with `expiring_secrets.enabled` only). */
    public const EXPIRY_ENTITY_NAMESPACE = 'Nowo\\InstanceSettingsBundle\\ExpiryEntity';

    /**
     * @param bool $checkOnly only check that the manager exists (registered before the mapping passes, which would
     *                        otherwise fail on its missing driver chain with a less helpful message)
     */
    public function __construct(
        private readonly bool $checkOnly = false,
    ) {
    }

    /**
     * Removes the bundle mapping from the managers other than `doctrine.entity_manager`.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(NowoInstanceSettingsExtension::DEDICATED_MAPPING_PARAMETER)) {
            return;
        }
        $name = $container->hasParameter(FileStoreMiddlewarePass::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(FileStoreMiddlewarePass::ENTITY_MANAGER_PARAMETER) : null;
        if (!is_string($name) || $name === '') {
            return;
        }

        $managers = $container->hasParameter('doctrine.entity_managers') ? $container->getParameter('doctrine.entity_managers') : [];
        $managers = is_array($managers) ? $managers : [];
        if (!isset($managers[$name])) {
            throw new InvalidConfigurationException(sprintf('nowo_instance_settings.doctrine.entity_manager is "%s", but no such Doctrine entity manager is configured (known: "%s"). Add it under doctrine.orm.entity_managers or remove the option.', $name, implode('", "', array_keys($managers))));
        }
        if ($this->checkOnly) {
            return;
        }

        foreach (array_keys($managers) as $manager) {
            $chainId = sprintf('doctrine.orm.%s_metadata_driver', $manager);
            if (!$container->hasDefinition($chainId)) {
                continue;
            }

            $chain = $container->getDefinition($chainId);
            $calls = [];
            $maps  = false;
            foreach ($chain->getMethodCalls() as $call) {
                $namespace = $call[0] === 'addDriver' ? ($call[1][1] ?? null) : null;
                $isBundle  = is_string($namespace) && (str_starts_with($namespace, self::ENTITY_NAMESPACE) || str_starts_with($namespace, self::FILE_ENTITY_NAMESPACE) || str_starts_with($namespace, self::EXPIRY_ENTITY_NAMESPACE));
                if ($isBundle && $manager !== $name) {
                    continue;
                }

                $maps    = $maps || ($isBundle && str_starts_with((string) $namespace, self::ENTITY_NAMESPACE));
                $calls[] = $call;
            }

            if ($manager !== $name) {
                // First in the chain: a chain asks its namespace drivers in order, then its default driver.
                array_unshift(
                    $calls,
                    ['addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), self::ENTITY_NAMESPACE]],
                    ['addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), self::FILE_ENTITY_NAMESPACE]],
                    ['addDriver', [new Definition(ExcludedEntityNamespaceDriver::class), self::EXPIRY_ENTITY_NAMESPACE]],
                );
            }

            $chain->setMethodCalls($calls);

            if ($manager === $name && !$maps) {
                throw new InvalidConfigurationException(sprintf('The Doctrine entity manager "%s" (nowo_instance_settings.doctrine.entity_manager) does not map %s.', $name, self::ENTITY_NAMESPACE));
            }
        }
    }
}
