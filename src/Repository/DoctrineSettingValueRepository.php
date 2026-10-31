<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Throwable;

use function sprintf;

/**
 * Doctrine ORM storage of the instance settings.
 *
 * Worker-safe without `kernel.reset`: the entity manager is resolved per call from the registry (a closed one is
 * reset), and reads use {@see Query::HINT_REFRESH} so rows are re-hydrated (and decrypted again) instead of being
 * served stale from the identity map of an earlier request.
 */
final readonly class DoctrineSettingValueRepository implements SettingValueRepositoryInterface
{
    /**
     * @param string|null $entityManagerName Doctrine entity manager name (null = default)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $entityManagerName = null,
    ) {
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
     * Persists and removes rows with a single flush; a failed flush resets a closed entity manager.
     */
    public function apply(array $save, array $remove): void
    {
        $entityManager = $this->entityManager();
        foreach ($save as $row) {
            $entityManager->persist($row);
        }
        foreach ($remove as $row) {
            // @igor-ignore - Unit-of-work scheduling on the request's entity manager; Doctrine resets it between requests.
            $entityManager->remove($row);
        }

        try {
            $entityManager->flush();
        } catch (Throwable $e) {
            if (!$entityManager->isOpen()) {
                $this->registry->resetManager($this->entityManagerName);
            }

            throw $e;
        }
    }

    /**
     * Wraps the callback in {@see EntityManagerInterface::wrapInTransaction()}.
     */
    public function transactional(callable $callback): mixed
    {
        return $this->entityManager()->wrapInTransaction(static fn (): mixed => $callback());
    }

    private function query(string $dql): Query
    {
        return $this->entityManager()
            ->createQuery(sprintf($dql, SettingValue::class))
            ->setHint(Query::HINT_REFRESH, true);
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = $this->registry->getManager($this->entityManagerName);
        if ($entityManager instanceof EntityManagerInterface && !$entityManager->isOpen()) {
            $entityManager = $this->registry->resetManager($this->entityManagerName);
        }

        if (!$entityManager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('Entity manager "%s" is not a Doctrine ORM entity manager.', $this->entityManagerName ?? 'default'));
        }

        return $entityManager;
    }
}
