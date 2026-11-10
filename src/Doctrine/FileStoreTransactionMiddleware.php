<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;

use function array_values;

/**
 * DBAL middleware of the bundle's connection: tells its observers when a **real** database transaction starts,
 * commits or rolls back, and about its savepoints. Introduced in 1.7 (audit JG-M1) for the filesystem file store, so
 * file changes made inside a transaction the host opened around a bundle call are applied after that transaction
 * commits and discarded when it rolls back; since 1.10 always registered, also for the after-commit dispatch of
 * {@see SettingsChangedEvent}. Stateless (worker-safe): the state lives in the
 * observers, which are reset with kernel.reset.
 */
final readonly class FileStoreTransactionMiddleware implements Middleware
{
    /** @var list<TransactionObserverInterface> */
    private array $observers;

    /**
     * @param TransactionObserverInterface ...$observers told about the transactions, in this order
     */
    public function __construct(TransactionObserverInterface ...$observers)
    {
        $this->observers = array_values($observers);
    }

    /**
     * Wraps the driver so its connections report their transactions.
     */
    public function wrap(Driver $driver): Driver
    {
        return new FileStoreTransactionDriver($driver, ...$this->observers);
    }
}
