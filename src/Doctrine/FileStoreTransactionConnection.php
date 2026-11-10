<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

use function array_values;
use function preg_match;
use function str_starts_with;
use function strtoupper;

/**
 * Driver connection of {@see FileStoreTransactionMiddleware}: reports the real transaction and its savepoints to the
 * observers (the filesystem store, the after-commit event dispatcher; DBAL nests transactions with savepoints issued
 * as SQL through {@see self::exec()}: `SAVEPOINT`,
 * `RELEASE SAVEPOINT`, `ROLLBACK TO SAVEPOINT`, and SQL Server's `SAVE TRANSACTION` / `ROLLBACK TRANSACTION`). A commit
 * that fails counts as a rollback.
 */
final class FileStoreTransactionConnection extends AbstractConnectionMiddleware
{
    /** Savepoint statements: verb and name. */
    private const SAVEPOINT_PATTERN = '/^\s*(SAVEPOINT|SAVE\s+TRAN(?:SACTION)?|RELEASE\s+SAVEPOINT|ROLLBACK\s+TO\s+SAVEPOINT|ROLLBACK\s+TRAN(?:SACTION)?)\s+([A-Za-z0-9_]+)\s*;?\s*$/i';

    /** @var list<TransactionObserverInterface> */
    private readonly array $observers;

    /**
     * @param Connection $connection wrapped driver connection
     * @param TransactionObserverInterface ...$observers told about the transactions, in this order
     */
    public function __construct(
        Connection $connection,
        TransactionObserverInterface ...$observers,
    ) {
        parent::__construct($connection);
        $this->observers = array_values($observers);
    }

    /**
     * Starts the transaction, then tells the observers.
     */
    public function beginTransaction(): void
    {
        parent::beginTransaction();
        foreach ($this->observers as $observer) {
            $observer->transactionStarted($this);
        }
    }

    /**
     * Commits, then lets the observers apply what the transaction held (discard it when the commit fails).
     */
    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $e) {
            $this->ended(false);

            throw $e;
        }
        $this->ended(true);
    }

    /**
     * Rolls back, then lets the observers discard what the transaction held.
     */
    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->ended(false);
        }
    }

    /**
     * Executes the statement; a successful savepoint statement is reported to the observers.
     */
    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        if (preg_match(self::SAVEPOINT_PATTERN, $sql, $match) === 1) {
            $verb = strtoupper($match[1]);
            foreach ($this->observers as $observer) {
                match (true) {
                    str_starts_with($verb, 'RELEASE')  => $observer->savepointReleased($this, $match[2]),
                    str_starts_with($verb, 'ROLLBACK') => $observer->savepointRolledBack($this, $match[2]),
                    default                            => $observer->savepointCreated($this, $match[2]),
                };
            }
        }

        return $result;
    }

    private function ended(bool $committed): void
    {
        foreach ($this->observers as $observer) {
            $observer->transactionEnded($this, $committed);
        }
    }
}
