<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\Doctrine\TransactionObserverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

use function array_slice;
use function count;
use function strcasecmp;

/**
 * Dispatches the bundle's events ({@see SettingsChangedEvent}) only once the write is committed (1.10, DDD P2).
 *
 * Without an open database transaction on the bundle's connection the event is dispatched at once (as before 1.10:
 * the bundle's own flush / transaction has already committed when it dispatches). Inside a transaction (one the host
 * opened around `save()`, an import, a seed inside a migration) the event is held by the innermost level and
 * dispatched after the **outermost commit**; a rollback (of the transaction or of the savepoint that held it) drops
 * it. The transactions are reported by {@see FileStoreTransactionMiddleware} for the bundle's Doctrine connection
 * only: a custom store on another backend gets an immediate dispatch while no transaction is open on that connection,
 * and a held one (dropped on its rollback) while one is.
 *
 * The state is keyed weakly by the driver connection (audit KI-I-L1): a connection closed or dropped in the middle of
 * a transaction (`Connection::close()` never rolls back) takes its pending events with it instead of holding every
 * later dispatch.
 *
 * A listener that throws during a deferred dispatch cannot fail the commit that already happened: the error is logged
 * and the remaining events are still dispatched. Worker-safe: the pending events are dropped on kernel.reset.
 */
final class AfterCommitEventDispatcher implements EventDispatcherInterface, TransactionObserverInterface, ResetInterface
{
    /**
     * Open transactions by driver connection, in the order they began: the real transaction, then one level per
     * savepoint, each with the events dispatched while it was the innermost level.
     *
     * @var WeakMap<object, list<array{0: string|null, 1: list<object>}>>
     */
    private WeakMap $transactions;

    /**
     * @param EventDispatcherInterface|null $dispatcher the application dispatcher (null = events are dropped)
     * @param LoggerInterface|null $logger receives the errors of deferred listeners
     */
    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->transactions = new WeakMap();
    }

    /**
     * Dispatches now (no open transaction) or holds the event until the outermost commit.
     */
    public function dispatch(object $event): object
    {
        $connection = null;
        foreach ($this->transactions as $open => $levels) {
            // The most recently started live transaction.
            $connection = $open;
        }
        if ($connection === null) {
            return $this->dispatcher?->dispatch($event) ?? $event;
        }
        $levels        = $this->transactions[$connection];
        $last          = count($levels) - 1;
        [$name, $held] = $levels[$last];
        $held[]        = $event;
        $levels[$last] = [$name, $held];
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection] = $levels;

        return $event;
    }

    /**
     * A real transaction started: a new, empty stack of levels.
     */
    public function transactionStarted(object $connection): void
    {
        unset($this->transactions[$connection]);
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection] = [[null, []]];
    }

    /**
     * The real transaction ended: a commit dispatches every event it holds, a rollback drops them.
     */
    public function transactionEnded(object $connection, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        unset($this->transactions[$connection]);
        if (!$committed) {
            return;
        }
        foreach ($this->flatten($levels) as $event) {
            try {
                $this->dispatcher?->dispatch($event);
            } catch (Throwable $e) {
                $this->logger?->error('Instance settings: a listener failed after the commit.', [
                    'bundle'    => 'NowoInstanceSettingsBundle',
                    'event'     => $event::class,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    /**
     * A savepoint was created: a new innermost level (a still open level of the same name was implicitly committed).
     */
    public function savepointCreated(object $connection, string $name): void
    {
        if (!isset($this->transactions[$connection])) {
            return;
        }
        $this->closeSavepoint($connection, $name, true);
        $levels   = $this->transactions[$connection];
        $levels[] = [$name, []];
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection] = $levels;
    }

    /**
     * A savepoint was released: its events go to the enclosing level.
     */
    public function savepointReleased(object $connection, string $name): void
    {
        $this->closeSavepoint($connection, $name, true);
    }

    /**
     * Rolled back to a savepoint: the events of that level (and of the levels inside it) are dropped.
     */
    public function savepointRolledBack(object $connection, string $name): void
    {
        $this->closeSavepoint($connection, $name, false);
    }

    /**
     * Drops every pending event (kernel.reset: a worker request that died mid-transaction).
     */
    public function reset(): void
    {
        $this->transactions = new WeakMap();
    }

    private function closeSavepoint(object $connection, string $name, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        foreach ($levels as $index => [$level]) {
            if ($index > 0 && $level !== null && strcasecmp($level, $name) === 0) {
                $closed = $this->flatten(array_slice($levels, $index));
                $levels = array_slice($levels, 0, $index);
                if ($committed) {
                    $parent             = count($levels) - 1;
                    $levels[$parent][1] = [...$levels[$parent][1], ...$closed];
                }
                // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
                $this->transactions[$connection] = $levels;

                return;
            }
        }
    }

    /**
     * @param list<array{0: string|null, 1: list<object>}> $levels
     *
     * @return list<object>
     */
    private function flatten(array $levels): array
    {
        $events = [];
        foreach ($levels as [, $level]) {
            $events = [...$events, ...$level];
        }

        return $events;
    }
}
