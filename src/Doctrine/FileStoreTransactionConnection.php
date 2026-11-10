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
        $this->notify(static fn (TransactionObserverInterface $observer, self $connection) => $observer->transactionStarted($connection));
    }

    /**
     * Commits, then lets the observers apply what the transaction held (discard it when the commit fails).
     */
    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $e) {
            try {
                $this->ended(false);
            } finally {
                // The commit failure wins over an observer failure.
                throw $e;
            }
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
            $name = $match[2];
            $this->notify(static fn (TransactionObserverInterface $observer, self $connection) => match (true) {
                str_starts_with($verb, 'RELEASE')  => $observer->savepointReleased($connection, $name),
                str_starts_with($verb, 'ROLLBACK') => $observer->savepointRolledBack($connection, $name),
                default                            => $observer->savepointCreated($connection, $name),
            });
        }

        return $result;
    }

    private function ended(bool $committed): void
    {
        $this->notify(static fn (TransactionObserverInterface $observer, self $connection) => $observer->transactionEnded($connection, $committed));
    }

    /**
     * Tells every observer, each in isolation (audit KI-I-L2): one that throws (a file write after the commit) does
     * not keep the others from being told; the first exception is rethrown once all were notified.
     *
     * @param callable(TransactionObserverInterface, self): mixed $notification
     */
    private function notify(callable $notification): void
    {
        $failure = null;
        foreach ($this->observers as $observer) {
            try {
                $notification($observer, $this);
            } catch (Throwable $e) {
                $failure ??= $e;
            }
        }
        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }
}
