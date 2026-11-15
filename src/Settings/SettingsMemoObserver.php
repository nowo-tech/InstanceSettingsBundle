<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Settings;

use Closure;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\Doctrine\TransactionObserverInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Drops the settings memo ({@see InstanceSettings}) when a transaction of the bundle's connection, or one of its
 * savepoints, rolls back (1.19, Beacon spec 239). Rows read inside a transaction may hold values the rollback undid (a
 * save inside a host transaction, then a read, then the host's rollback): without this, the request (or a console command
 * or Messenger message of a long-running worker) kept serving them until its end. Told by
 * {@see FileStoreTransactionMiddleware}. Stateless.
 */
final readonly class SettingsMemoObserver implements TransactionObserverInterface
{
    /**
     * @param Closure(): (ResetInterface|null) $settings the settings memo, when already built (lazy: the connection's
     *                                                   middleware is built before the settings services)
     */
    public function __construct(
        private Closure $settings,
    ) {
    }

    /**
     * Nothing to do when a transaction starts.
     */
    public function transactionStarted(object $connection): void
    {
    }

    /**
     * A rollback drops the memo.
     */
    public function transactionEnded(object $connection, bool $committed): void
    {
        if (!$committed) {
            $this->reset();
        }
    }

    /**
     * Nothing to do when a savepoint is created.
     */
    public function savepointCreated(object $connection, string $name): void
    {
    }

    /**
     * Nothing to do when a savepoint is released.
     */
    public function savepointReleased(object $connection, string $name): void
    {
    }

    /**
     * A rollback to a savepoint drops the memo.
     */
    public function savepointRolledBack(object $connection, string $name): void
    {
        $this->reset();
    }

    private function reset(): void
    {
        ($this->settings)()?->reset();
    }
}
