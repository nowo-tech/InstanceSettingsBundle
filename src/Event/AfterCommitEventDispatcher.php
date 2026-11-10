<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\Doctrine\TransactionObserverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_key_last;
use function array_slice;
use function count;
use function spl_object_id;
use function strcasecmp;

/**
 * Dispatches the bundle's events ({@see SettingsChangedEvent}) only once the write is committed (1.10, DDD P2).
 *
 * Without an open database transaction on the bundle's connection the event is dispatched at once (as before 1.10:
 * the bundle's own flush / transaction has already committed when it dispatches). Inside a transaction (one the host
 * opened around `save()`, an import, a seed inside a migration) the event is held by the innermost level and
 * dispatched after the **outermost commit**; a rollback (of the transaction or of the savepoint that held it) drops
 * it. The transactions are reported by {@see FileStoreTransactionMiddleware}; with a store that does not use the
 * bundle's Doctrine connection the dispatch is immediate.
 *
 * A listener that throws during a deferred dispatch cannot fail the commit that already happened: the error is logged
 * and the remaining events are still dispatched. Worker-safe: the pending events are dropped on kernel.reset.
 */
final class AfterCommitEventDispatcher implements EventDispatcherInterface, TransactionObserverInterface, ResetInterface
{
    /**
     * Open transactions keyed by connection, in the order they began: the real transaction, then one level per
     * savepoint, each with the events dispatched while it was the innermost level.
     *
     * @var array<int, list<array{0: string|null, 1: list<object>}>>
     */
    private array $transactions = [];

    /**
     * @param EventDispatcherInterface|null $dispatcher the application dispatcher (null = events are dropped)
     * @param LoggerInterface|null $logger receives the errors of deferred listeners
     */
    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Dispatches now (no open transaction) or holds the event until the outermost commit.
     */
    public function dispatch(object $event): object
    {
        $connection = array_key_last($this->transactions);
        if ($connection === null) {
            return $this->dispatcher?->dispatch($event) ?? $event;
        }
        $level = count($this->transactions[$connection]) - 1;
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$connection][$level][1][] = $event;

        return $event;
    }

    /**
     * A real transaction started: a new, empty stack of levels.
     */
    public function transactionStarted(object $connection): void
    {
        unset($this->transactions[spl_object_id($connection)]);
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[spl_object_id($connection)] = [[null, []]];
    }

    /**
     * The real transaction ended: a commit dispatches every event it holds, a rollback drops them.
     */
    public function transactionEnded(object $connection, bool $committed): void
    {
        $levels = $this->transactions[spl_object_id($connection)] ?? [];
        unset($this->transactions[spl_object_id($connection)]);
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
        $id = spl_object_id($connection);
        if (!isset($this->transactions[$id])) {
            return;
        }
        $this->closeSavepoint($id, $name, true);
        // @igor-ignore - Pending events of the open transaction; dropped on commit / rollback and on kernel.reset.
        $this->transactions[$id][] = [$name, []];
    }

    /**
     * A savepoint was released: its events go to the enclosing level.
     */
    public function savepointReleased(object $connection, string $name): void
    {
        $this->closeSavepoint(spl_object_id($connection), $name, true);
    }

    /**
     * Rolled back to a savepoint: the events of that level (and of the levels inside it) are dropped.
     */
    public function savepointRolledBack(object $connection, string $name): void
    {
        $this->closeSavepoint(spl_object_id($connection), $name, false);
    }

    /**
     * Drops every pending event (kernel.reset: a worker request that died mid-transaction).
     */
    public function reset(): void
    {
        $this->transactions = [];
    }

    private function closeSavepoint(int $connection, string $name, bool $committed): void
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
