<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Nowo\InstanceSettingsBundle\File\FilesystemSettingFileStore;

/**
 * Driver of {@see FileStoreTransactionMiddleware}: wraps each connection in a {@see FileStoreTransactionConnection}.
 */
final class FileStoreTransactionDriver extends AbstractDriverMiddleware
{
    /**
     * @param Driver $driver wrapped driver
     * @param FilesystemSettingFileStore $store store told about the transactions
     */
    public function __construct(
        Driver $driver,
        private readonly FilesystemSettingFileStore $store,
    ) {
        parent::__construct($driver);
    }

    /**
     * Connects and wraps the connection.
     */
    public function connect(array $params): Connection
    {
        return new FileStoreTransactionConnection(parent::connect($params), $this->store);
    }
}
