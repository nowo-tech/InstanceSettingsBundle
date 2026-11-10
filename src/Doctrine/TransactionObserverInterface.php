<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Nowo\InstanceSettingsBundle\Event\AfterCommitEventDispatcher;

/**
 * Told by {@see FileStoreTransactionMiddleware} about the **real** database transactions of the bundle's connection and
 * their savepoints (1.10): the filesystem file store applies its file changes, and {@see AfterCommitEventDispatcher}
 * its events, only once the outermost transaction committed, and drops them on rollback.
 *
 * The connection argument is the wrapped driver connection; observers key their state by its object id.
 */
interface TransactionObserverInterface
{
    /**
     * A real database transaction started on `$connection`.
     */
    public function transactionStarted(object $connection): void;

    /**
     * The real transaction of `$connection` ended: committed, or rolled back (a failed commit counts as a rollback).
     */
    public function transactionEnded(object $connection, bool $committed): void;

    /**
     * A savepoint was created on `$connection`.
     */
    public function savepointCreated(object $connection, string $name): void;

    /**
     * A savepoint was released (committed into its parent) on `$connection`.
     */
    public function savepointReleased(object $connection, string $name): void;

    /**
     * The connection rolled back to a savepoint.
     */
    public function savepointRolledBack(object $connection, string $name): void;
}
