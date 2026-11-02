<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Seed;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Throwable;

use function is_object;
use function method_exists;
use function sprintf;

/**
 * Best-effort detection of a "phantom" DBAL transaction before {@see FallbackSeeder} writes.
 *
 * On MySQL / MariaDB a DDL statement commits implicitly. When a Doctrine migration with DDL runs in a transactional
 * migration, Doctrine Migrations skips its own commit and DBAL keeps a nesting level although the server has no open
 * transaction any more; a later `wrapInTransaction()` then issues `SAVEPOINT` / `RELEASE SAVEPOINT` against nothing
 * and fails with "SAVEPOINT DOCTRINE_n does not exist" (or silently writes outside any transaction). This guard
 * refuses to seed in that state with an actionable message instead of corrupting it.
 *
 * Only checked when the native connection reports its state (PDO `inTransaction()`); otherwise (mysqli, other
 * drivers, no ORM entity manager) it does nothing. Stateless (worker-safe).
 */
final readonly class TransactionStateGuard
{
    /**
     * @param ManagerRegistry $registry Doctrine registry (the entity manager is resolved per call)
     * @param string|null $entityManagerName entity manager of the settings store (null = default)
     */
    public function __construct(
        private ManagerRegistry $registry,
        private ?string $entityManagerName = null,
    ) {
    }

    /**
     * Checks the connection of the settings entity manager.
     *
     * @throws LogicException when DBAL believes a transaction is open but the database connection has none
     */
    public function check(): void
    {
        $entityManager = $this->registry->getManager($this->entityManagerName);
        if ($entityManager instanceof EntityManagerInterface) {
            self::assertConsistent($entityManager->getConnection());
        }
    }

    /**
     * Throws when `$connection` has a DBAL transaction nesting level but its native connection is not in a
     * transaction. Best effort: unknown native connections (or a failure to read them) pass.
     *
     * @throws LogicException on a phantom transaction
     */
    public static function assertConsistent(Connection $connection): void
    {
        $level = $connection->getTransactionNestingLevel();
        if ($level === 0) {
            return;
        }

        try {
            $native = $connection->getNativeConnection();
        } catch (Throwable) {
            return;
        }
        if (!is_object($native) || !method_exists($native, 'inTransaction') || $native->inTransaction() === true) {
            return;
        }

        throw new LogicException(sprintf('Cannot seed the instance settings: Doctrine reports %d open transaction level(s) but the database connection has none (typically an implicit commit by a DDL statement in a transactional migration). Call the seeder from a non-transactional migration (isTransactional(): false, or doctrine_migrations.transactional: false) or outside any transaction.', $level));
    }
}
