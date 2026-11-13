<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\Persistence\Mapping\MappingException;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\EntityManagerMappingPass;

use function sprintf;

/**
 * Metadata driver that maps nothing: registered for the bundle entity namespaces on every entity manager other than
 * `doctrine.entity_manager` by {@see EntityManagerMappingPass}.
 *
 * A {@see MappingDriverChain} asks its namespace drivers before its default driver, so this driver makes the bundle
 * entities transient on those managers even when their chain has a default driver that claims every class carrying
 * `#[ORM\Entity]` (DoctrineBundle 3.4 `auto_discover_entities`, enabled by default on a manager without `mappings`
 * and `auto_mapping`). `ManagerRegistry::getManagerForClass()` then answers the configured manager on DoctrineBundle
 * 2.x and 3.x alike. Stateless (worker-safe).
 */
final class ExcludedEntityNamespaceDriver implements MappingDriver
{
    /**
     * Always fails: no bundle class is mapped on this manager.
     *
     * @throws MappingException
     */
    public function loadMetadataForClass(string $className, ClassMetadata $metadata): void
    {
        throw new MappingException(sprintf('Class "%s" is not mapped on this entity manager (nowo_instance_settings.doctrine.entity_manager maps it on another one).', $className));
    }

    /**
     * No class: the bundle entities are listed by the configured manager only.
     *
     * @return list<class-string>
     */
    public function getAllClassNames(): array
    {
        return [];
    }

    /**
     * Every bundle class is transient on this manager.
     */
    public function isTransient(string $className): bool
    {
        return true;
    }
}
