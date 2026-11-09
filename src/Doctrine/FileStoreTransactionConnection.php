<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Throwable;

use function preg_match;
use function str_starts_with;
use function strtoupper;

/**
 * Driver connection of {@see FileStoreTransactionMiddleware}: reports the real transaction and its savepoints to the
 * filesystem store (DBAL nests transactions with savepoints issued as SQL through {@see self::exec()}: `SAVEPOINT`,
 * `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT`, and SQL Server's `SAVE TRANSACTION` / `ROLLBACK TRANSACTION`). A commit
 * that fails counts as a rollback.
 */
final class FileStoreTransactionConnection extends AbstractConnectionMiddleware
{
    /** Savepoint statements: verb and name. */
    private const SAVEPOINT_PATTERN = '/^\s*(SAVEPOINT|SAVE\s+TRAN(?:SACTION)?|RELEASE\s+SAVEPOINT|ROLLBACK\s+TO\s+SAVEPOINT|ROLLBACK\s+TRAN(?:SACTION)?)\s+([A-Za-z0-9_]+)\s*;?\s*$/i';

    /**
     * @param Connection $connection wrapped driver connection
     * @param FilesystemSettingFileStore $store store told about the transactions
     */
    public function __construct(
        Connection $connection,
        private readonly FilesystemSettingFileStore $store,
    ) {
        parent::__construct($connection);
    }

    /**
     * Starts the transaction, then tells the store.
     */
    public function beginTransaction(): void
    {
        parent::beginTransaction();
        $this->store->transactionStarted($this);
    }

    /**
     * Commits, then lets the store apply the file changes held by the transaction (discards them when the commit fails).
     */
    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $e) {
            $this->store->transactionEnded($this, false);

            throw $e;
        }
        $this->store->transactionEnded($this, true);
    }

    /**
     * Rolls back, then lets the store discard the file changes held by the transaction.
     */
    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->store->transactionEnded($this, false);
        }
    }

    /**
     * Executes the statement; a successful savepoint statement is reported to the store.
     */
    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        if (preg_match(self::SAVEPOINT_PATTERN, $sql, $match) === 1) {
            $verb = strtoupper($match[1]);
            match (true) {
                str_starts_with($verb, 'RELEASE')  => $this->store->savepointReleased($this, $match[2]),
                str_starts_with($verb, 'ROLLBACK') => $this->store->savepointRolledBack($this, $match[2]),
                default                            => $this->store->savepointCreated($this, $match[2]),
            };
        }

        return $result;
    }
}
