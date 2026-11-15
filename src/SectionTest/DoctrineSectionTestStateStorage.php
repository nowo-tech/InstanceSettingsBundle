<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\SectionTest;

use DateTimeImmutable;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Transaction\InstanceSettingsTransactionRunner;
use Throwable;

use function is_string;
use function sprintf;

/**
 * Doctrine storage of the section test state (1.18): rows of the settings table (`instance_setting_value`, with
 * `doctrine.table_prefix`) under the reserved sections, written through the bundle's private entity manager (rule 6).
 *
 * The insert relies on the table's unique (section, name) key; the update and the delete are single DQL statements
 * whose WHERE names the row's id and its time as read, so the database decides who wins (audit PU-L2 / L3). A lost
 * insert closes the private manager, which is replaced on the next call; the manager is cleared after every write.
 *
 * Stateless (worker-safe): nothing is memoised; reads refresh the rows.
 *
 * @internal not covered by the backward compatibility promise
 */
final readonly class DoctrineSectionTestStateStorage implements SectionTestStateStorageInterface
{
    private InstanceSettingsTransactionRunner $runner;

    /**
     * @param SettingsEntityManager $entityManager the bundle's private entity manager
     * @param InstanceSettingsTransactionRunner|null $runner 1.19: the bundle's runner (null = one on the private manager)
     */
    public function __construct(
        private SettingsEntityManager $entityManager,
        ?InstanceSettingsTransactionRunner $runner = null,
    ) {
        $this->runner = $runner ?? new InstanceSettingsTransactionRunner($entityManager);
    }

    /**
     * Every row of a reserved section.
     */
    public function rows(string $section): array
    {
        /** @var list<SettingValue> $entities */
        $entities = $this->entityManager->get()
            ->createQuery(sprintf('SELECT s FROM %s s WHERE s.section = :section ORDER BY s.id ASC', SettingValue::class))
            ->setParameter('section', $section)
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
        $rows = [];
        foreach ($entities as $entity) {
            $rows[] = $this->view($entity);
        }
        // @igor-ignore - Clears the bundle's own private unit of work after the read (rule 6), never an application manager.
        $this->entityManager->clear();

        return $rows;
    }

    /**
     * Inserts a row; null when the (section, name) row exists.
     */
    public function insert(string $section, string $name, string $value, DateTimeImmutable $at): ?SectionTestStateRow
    {
        $entity        = (new SettingValue($section, $name, $at))->storeValue($value, $at);
        $entityManager = $this->entityManager->get();

        try {
            // 1.19: one insert in a unit of the bundle's runner (a savepoint inside a caller's transaction, so a lost
            // insert never aborts it on PostgreSQL); the runner forgets the refused row.
            // @igor-ignore - The closure runs synchronously inside the unit of work and is not stored.
            $this->runner->run(static function () use ($entityManager, $entity): void {
                $entityManager->persist($entity);
            });
        } catch (Throwable $e) {
            // The unique key refused the insert: another request holds the row. Anything else reaches the caller.
            if ($e instanceof UniqueConstraintViolationException) {
                return null;
            }

            throw $e;
        } finally {
            // @igor-ignore - Clears the bundle's own private unit of work at the end of the write (rule 6).
            $this->entityManager->clear();
        }

        return $this->view($entity);
    }

    /**
     * Conditional update (same id and time as read).
     */
    public function update(SectionTestStateRow $row, string $value, DateTimeImmutable $at): bool
    {
        return $this->entityManager->get()
            ->createQuery(sprintf('UPDATE %s s SET s.value = :value, s.updatedAt = :at WHERE s.id = :id AND s.section = :section AND s.updatedAt = :read', SettingValue::class))
            ->setParameter('value', $value, Types::JSON)
            ->setParameter('at', $at, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $row->id)
            ->setParameter('section', $row->section)
            ->setParameter('read', $row->updatedAt, Types::DATETIME_IMMUTABLE)
            ->execute() === 1;
    }

    /**
     * Conditional delete (same id and time as read).
     */
    public function delete(SectionTestStateRow $row): bool
    {
        return $this->entityManager->get()
            ->createQuery(sprintf('DELETE FROM %s s WHERE s.id = :id AND s.section = :section AND s.updatedAt = :read', SettingValue::class))
            ->setParameter('id', $row->id)
            ->setParameter('section', $row->section)
            ->setParameter('read', $row->updatedAt, Types::DATETIME_IMMUTABLE)
            ->execute() === 1;
    }

    private function view(SettingValue $entity): SectionTestStateRow
    {
        $value = $entity->getValue();

        return new SectionTestStateRow((int) $entity->getId(), $entity->getSection(), $entity->getName(), is_string($value) ? $value : null, $entity->getUpdatedAt());
    }
}
