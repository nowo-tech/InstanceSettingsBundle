<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use DateTimeImmutable;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\InstanceSettingsBundle\Doctrine\SequenceId;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Event\SettingsPendingEventsInterface;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTestStore;
use Nowo\InstanceSettingsBundle\Transaction\InstanceSettingsTransactionRunner;
use Nowo\TransactionContract\TransientStorageFailure;

use function array_filter;
use function array_values;
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
 *
 * 1.19 (Beacon spec 239): {@see self::transactional()} runs through the bundle's own
 * {@see InstanceSettingsTransactionRunner} (a transient storage failure is a {@see TransientStorageFailure}, and a failed
 * unit leaves nothing pending in the private manager), and the writers of a section are serialized by
 * {@see self::lockSection()} ({@see SectionLock}). Every read re-checks the section name byte for byte (a folding
 * collation of an existing table can match a look-alike).
 */
final class DoctrineSettingValueRepository implements SettingValueRepositoryInterface, SectionLockingRepositoryInterface
{
    private readonly SettingsEntityManager $entityManager;

    private readonly InstanceSettingsTransactionRunner $runner;

    /** Open {@see transactional()} levels: the private manager is cleared when the outermost one ends (back to 0 in finally). */
    private int $depth = 0;

    /**
     * @param ManagerRegistry $registry Doctrine registry
     * @param string|null $entityManagerName `doctrine.entity_manager` (null = the manager that maps the entity)
     * @param SettingsEntityManager|null $entityManager the bundle's private manager (shared with the file store)
     * @param SettingsPendingEventsInterface|null $pendingEvents dispatches the events committed by {@see transactional()}
     *                                                           once it returned (1.11.1)
     * @param InstanceSettingsTransactionRunner|null $runner the bundle's runner (1.19; null = one on the private manager)
     */
    public function __construct(
        ManagerRegistry $registry,
        ?string $entityManagerName = null,
        ?SettingsEntityManager $entityManager = null,
        private readonly ?SettingsPendingEventsInterface $pendingEvents = null,
        ?InstanceSettingsTransactionRunner $runner = null,
    ) {
        $this->entityManager = $entityManager ?? new SettingsEntityManager($registry, $entityManagerName);
        $this->runner        = $runner ?? new InstanceSettingsTransactionRunner($this->entityManager);
    }

    /**
     * Every stored setting row, ordered by section and name (the reserved section test rows left out, 1.18).
     */
    public function findAll(): array
    {
        // 1.18 (audit PU-L6): the section test state (reserved `_section_test_*` sections) is not a setting.
        /** @var list<SettingValue> $rows */
        $rows = $this->query('SELECT s FROM %s s WHERE s.section NOT IN (:reserved) ORDER BY s.section ASC, s.name ASC')
            ->setParameter('reserved', [SectionTestStore::REPORTS, SectionTestStore::LOCKS, SectionTestStore::RATES, SectionLock::SECTION])
            ->getResult();

        return $rows;
    }

    /**
     * Rows of one section, ordered by name (the section name re-checked byte for byte).
     */
    public function findBySection(string $section): array
    {
        /** @var list<SettingValue> $rows */
        $rows = $this->query('SELECT s FROM %s s WHERE s.section = :section ORDER BY s.name ASC')
            ->setParameter('section', $section)
            ->getResult();

        return $this->exactly($rows, $section);
    }

    /**
     * Rows of one section as last committed: a locking read inside the open unit (1.19).
     */
    public function findBySectionForUpdate(string $section): array
    {
        /** @var list<SettingValue> $rows */
        $rows = $this->query('SELECT s FROM %s s WHERE s.section = :section ORDER BY s.name ASC')
            ->setParameter('section', $section)
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();

        return $this->exactly($rows, $section);
    }

    /**
     * Takes the write lock of a section in the open unit (1.19, {@see SectionLock}).
     */
    public function lockSection(string $section): void
    {
        $entityManager = $this->entityManager->get();
        $metadata      = $entityManager->getClassMetadata(SettingValue::class);
        $connection    = $entityManager->getConnection();
        $id            = $connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? null : SequenceId::next($connection, $metadata);
        SectionLock::acquire($connection, $metadata->getTableName(), [
            'section'    => $metadata->getColumnName('section'),
            'name'       => $metadata->getColumnName('name'),
            'updated_at' => $metadata->getColumnName('updatedAt'),
            'id'         => $metadata->getSingleIdentifierColumnName(),
        ], $section, new DateTimeImmutable(), $id);
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
     * Runs the callback in one transaction of the private entity manager (on the shared connection), through the bundle's
     * runner (1.19): one attempt; a transient failure is a {@see TransientStorageFailure}.
     */
    public function transactional(callable $callback): mixed
    {
        // @igor-ignore - Nesting counter of the running call, back to 0 in finally (never spans requests).
        ++$this->depth;
        try {
            $result = $this->runner->run($callback);
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

    /**
     * The rows whose section is exactly `$section` (a folding or PAD SPACE collation matches look-alikes).
     *
     * @param list<SettingValue> $rows
     *
     * @return list<SettingValue>
     */
    private function exactly(array $rows, string $section): array
    {
        return array_values(array_filter($rows, static fn (SettingValue $row): bool => $row->getSection() === $section));
    }

    private function query(string $dql): Query
    {
        return $this->entityManager->get()
            ->createQuery(sprintf($dql, SettingValue::class))
            ->setHint(Query::HINT_REFRESH, true);
    }
}
