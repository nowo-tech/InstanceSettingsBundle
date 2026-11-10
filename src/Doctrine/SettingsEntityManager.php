<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\AfterCommitEventDispatcher;
use Symfony\Contracts\Service\ResetInterface;

use function sprintf;

/**
 * The bundle's own entity manager (1.11, architecture bar rule 6).
 *
 * The settings store never flushes, clears, closes or resets an entity manager of the application. It reads and writes
 * through a **private** entity manager created on the connection, configuration (mapping, metadata cache) and event
 * manager (DoctrineEncrypt, table prefix) of the manager that maps {@see SettingValue}: `doctrine.entity_manager` when
 * it is set, else the one `doctrine` returns for the entity class (the default manager of a standard application). Its
 * own unit of work holds only bundle rows, so a save never commits a change the application left pending, and a
 * failed write closes only this private manager. The connection is shared: a transaction the application opened
 * around a bundle call includes the bundle's rows (and {@see AfterCommitEventDispatcher}
 * holds the events until its outermost commit).
 *
 * Worker-safe without `kernel.reset`: a closed private manager, or one whose connection is not the source manager's
 * any more, is replaced on the next call; the repositories clear it after every write unit.
 */
final class SettingsEntityManager implements ResetInterface
{
    private ?EntityManagerInterface $entityManager = null;

    /**
     * @param ManagerRegistry $registry Doctrine registry (the source manager is resolved per call)
     * @param string|null $entityManagerName `doctrine.entity_manager` (null = the manager that maps the bundle entity)
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly ?string $entityManagerName = null,
    ) {
    }

    /**
     * The open private entity manager, created (again) when needed.
     */
    public function get(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->entityManager;
        if ($current instanceof EntityManagerInterface && $current->isOpen() && $current->getConnection() === $source->getConnection()) {
            return $current;
        }

        // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
        $eventManager = $source->getEventManager();

        // @igor-ignore - Bundle-owned unit of work: cleared after every write unit, replaced when closed or when the connection changed.
        return $this->entityManager = new EntityManager($source->getConnection(), $source->getConfiguration(), $eventManager instanceof EventManager ? $eventManager : null);
    }

    /**
     * Detaches every row from the private manager (end of a write unit). Never touches an application manager.
     */
    public function clear(): void
    {
        $this->entityManager?->clear();
    }

    /**
     * Drops the private manager (kernel.reset); the next call creates a fresh one.
     */
    public function reset(): void
    {
        $this->entityManager?->clear();
        $this->entityManager = null;
    }

    /**
     * The application's manager whose connection, configuration and events the private one shares.
     */
    public function source(): EntityManagerInterface
    {
        $source = $this->entityManagerName === null
            ? $this->registry->getManagerForClass(SettingValue::class)
            : $this->registry->getManager($this->entityManagerName);
        if (!$source instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('Entity manager "%s" is not a Doctrine ORM entity manager that maps %s.', $this->entityManagerName ?? 'default', SettingValue::class));
        }

        return $source;
    }
}
