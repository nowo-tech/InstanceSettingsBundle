<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;

/**
 * DBAL middleware of the filesystem file store (1.7, audit JG-M1), registered only with `files.store: filesystem`:
 * tells the store when a **real** database transaction starts, commits or rolls back (nested levels are savepoints and
 * never reach the driver), so file changes made inside a transaction the host opened around a bundle call are applied
 * after that transaction commits and discarded when it rolls back. Stateless (worker-safe): the state lives in the
 * store, which is reset with kernel.reset.
 */
final readonly class FileStoreTransactionMiddleware implements Middleware
{
    /**
     * @param FilesystemSettingFileStore $store store told about the transactions
     */
    public function __construct(
        private FilesystemSettingFileStore $store,
    ) {
    }

    /**
     * Wraps the driver so its connections report their transactions.
     */
    public function wrap(Driver $driver): Driver
    {
        return new FileStoreTransactionDriver($driver, $this->store);
    }
}
