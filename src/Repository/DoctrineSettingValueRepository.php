<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsPendingEventsInterface;

use function sprintf;

/**
 * Doctrine ORM storage of the instance settings.
 *
 * Since 1.11 (architecture bar rule 6) every read and write goes through the bundle's private entity manager
 * ({@see SettingsEntityManager}): a save flushes only the bundle rows, never a change the application left pending in
 * its own entity manager, and no application manager is ever reset. The connection is shared, so a transaction the
 * application opened around the call includes the rows.
 *
 * Worker-safe without `kernel.reset`: the private manager is cleared after every write unit and replaced when closed,
 * and reads use {@see Query::HINT_REFRESH} so rows are re-hydrated (and decrypted again) instead of being served stale
 * from the identity map of an earlier request.
 */
final class DoctrineSettingValueRepository implements SettingValueRepositoryInterface
{
    private readonly SettingsEntityManager $entityManager;

    /** Open {@see transactional()} levels: the private manager is cleared when the outermost one ends (back to 0 in finally). */
    private int $depth = 0;

    /**
     * @param ManagerRegistry $registry Doctrine registry
     * @param string|null $entityManagerName `doctrine.entity_manager` (null = the manager that maps the entity)
     * @param SettingsEntityManager|null $entityManager the bundle's private manager (shared with the file store)
     * @param SettingsPendingEventsInterface|null $pendingEvents dispatches the events committed by {@see transactional()}
     *                                                           once it returned (1.11.1)
     */
    public function __construct(
        ManagerRegistry $registry,
        ?string $entityManagerName = null,
        ?SettingsEntityManager $entityManager = null,
        private readonly ?SettingsPendingEventsInterface $pendingEvents = null,
    ) {
        $this->entityManager = $entityManager ?? new SettingsEntityManager($registry, $entityManagerName);
    }

    /**
     * Every stored row, ordered by section and name.
     */
    public function findAll(): array
    {
        /** @var list<SettingValue> $rows */
        $rows = $this->query('SELECT s FROM %s s ORDER BY s.section ASC, s.name ASC')->getResult();

        return $rows;
    }

    /**
     * Rows of one section, ordered by name.
     */
    public function findBySection(string $section): array
    {
        /** @var list<SettingValue> $rows */
        $rows = $this->query('SELECT s FROM %s s WHERE s.section = :section ORDER BY s.name ASC')
            ->setParameter('section', $section)
            ->getResult();

        return $rows;
    }

    /**
     * Persists and removes rows with a single flush of the bundle's private entity manager (never the application's).
     * A failed flush closes only that private manager; the next call gets a fresh one.
     */
    public function apply(array $save, array $remove): void
    {
        $entityManager = $this->entityManager->get();
        foreach ($save as $row) {
            $entityManager->persist($row);
        }
        foreach ($remove as $row) {
            // @igor-ignore - Unit-of-work scheduling on the bundle's private entity manager, cleared after the write unit.
            $entityManager->remove($row);
        }

        try {
            $entityManager->flush();
        } finally {
            if ($this->depth === 0) {
                // @igor-ignore - Clears the bundle's own private unit of work at the end of the write unit (rule 6).
                $this->entityManager->clear();
            }
        }
    }

    /**
     * Runs the callback in one transaction of the private entity manager (on the shared connection).
     */
    public function transactional(callable $callback): mixed
    {
        // @igor-ignore - Nesting counter of the running call, back to 0 in finally (never spans requests).
        ++$this->depth;
        try {
            $result = $this->entityManager->get()->wrapInTransaction(static fn (): mixed => $callback());
        } finally {
            // @igor-ignore - Nesting counter of the running call, back to 0 in finally (never spans requests).
            if (--$this->depth === 0) {
                // @igor-ignore - Clears the bundle's own private unit of work at the end of the write unit (rule 6).
                $this->entityManager->clear();
            }
        }
        $this->afterTransactional();

        return $result;
    }

    /**
     * After the outermost {@see transactional()} returned: its committed events, when no transaction is open any more.
     */
    private function afterTransactional(): void
    {
        if ($this->depth === 0) {
            $this->pendingEvents?->dispatchPending();
        }
    }

    private function query(string $dql): Query
    {
        return $this->entityManager->get()
            ->createQuery(sprintf($dql, SettingValue::class))
            ->setHint(Query::HINT_REFRESH, true);
    }
}
