<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;
use Throwable;

/**
 * Driver connection of {@see FileStoreTransactionMiddleware}: reports each real transaction to the filesystem store.
 * A commit that fails counts as a rollback.
 */
final class FileStoreTransactionConnection extends AbstractConnectionMiddleware
{
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
        $this->store->transactionStarted();
    }

    /**
     * Commits, then lets the store apply its deferred file changes (discards them when the commit fails).
     */
    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $e) {
            $this->store->transactionEnded(false);

            throw $e;
        }
        $this->store->transactionEnded(true);
    }

    /**
     * Rolls back, then lets the store discard its deferred file changes.
     */
    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->store->transactionEnded(false);
        }
    }
}
