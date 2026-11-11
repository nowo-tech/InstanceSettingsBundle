<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

use Closure;
use Doctrine\DBAL\Connection;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Doctrine\TransactionObserverInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\HttpKernel\KernelEvents;
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
 * marked committed by the **outermost commit**; a rollback (of the transaction or of the savepoint that held it) drops
 * it. The transactions are reported by {@see FileStoreTransactionMiddleware} for the bundle's Doctrine connection
 * only: a custom store on another backend gets an immediate dispatch while no transaction is open on that connection,
 * and a held one (dropped on its rollback) while one is.
 *
 * The state is keyed weakly by the driver connection (audit KI-I-L1): a connection closed or dropped in the middle of
 * a transaction (`Connection::close()` never rolls back) takes its pending events with it instead of holding every
 * later dispatch.
 *
 * Committed events are **not** dispatched from inside the driver commit (1.11.1): DBAL still reports the transaction
 * as active there, so a listener's own write (an audit row flushed on the application manager) would nest into a
 * transaction that is ending. They are dispatched at the next point where the bundle's connection reports no open
 * transaction: the next event dispatched through this dispatcher, a Doctrine `postFlush`,
 * {@see SettingsPendingEventsInterface::dispatchPending()}, and the end of the request, console command or Messenger
 * message.
 *
 * A listener that throws during a deferred dispatch cannot fail the commit that already happened: the error is logged
 * and the remaining events are still dispatched. Worker-safe: the pending events are dropped on kernel.reset.
 */
final class AfterCommitEventDispatcher implements EventDispatcherInterface, SettingsPendingEventsInterface, TransactionObserverInterface, ResetInterface
{
    /**
     * The end of the request, console command and Messenger message (symfony/messenger is optional: event names as
     * strings): {@see onBoundary()} is registered for each by the bundle.
     */
    public const BOUNDARY_EVENTS = [
        KernelEvents::TERMINATE,
        ConsoleEvents::TERMINATE,
        'Symfony\\Component\\Messenger\\Event\\WorkerMessageHandledEvent',
        'Symfony\\Component\\Messenger\\Event\\WorkerMessageFailedEvent',
    ];

    /**
     * Open transactions by driver connection, in the order they began: the real transaction, then one level per
     * savepoint, each with the events dispatched while it was the innermost level.
     *
     * @var WeakMap<object, list<array{0: string|null, 1: list<object>}>>
     */
    private WeakMap $transactions;

    /**
     * Events of committed writes not dispatched yet (taken at the next safe point).
     *
     * @var list<object>
     */
    // @igor-ignore - Committed events dispatched at the next safe point (end of request at the latest); kernel.reset.
    private array $committed = [];

    /**
     * @param EventDispatcherInterface|null $dispatcher the application dispatcher (null = events are dropped)
     * @param LoggerInterface|null $logger receives the errors of deferred listeners
     * @param (Closure(): (Connection|SettingsEntityManager|null))|null $connection the bundle's connection, or the
     *                                                                              private manager that shares it (lazy:
     *                                                                              the connection's middleware depends
     *                                                                              on this service)
     */
    public function __construct(
        private readonly ?EventDispatcherInterface $dispatcher = null,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?Closure $connection = null,
    ) {
        $this->transactions = new WeakMap();
    }

    /**
     * Unit-of-work boundary: dispatches the committed events.
     */
    public function onBoundary(): void
    {
        $this->dispatchPending();
    }

    /**
     * Doctrine `postFlush` (after the flush's own commit returned).
     */
    public function postFlush(): void
    {
        $this->dispatchPending();
    }

    /**
     * Dispatches the committed events when no transaction is open (a failing listener is logged).
     */
    public function dispatchPending(): int
    {
        $count = 0;
        while ($this->committed !== [] && $this->safe()) {
            $events          = $this->committed;
            $this->committed = [];
            foreach ($events as $event) {
                ++$count;
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

        return $count;
    }

    /**
     * Events held by open transactions plus committed events not dispatched yet.
     */
    public function countPending(): int
    {
        $count = count($this->committed);
        foreach ($this->transactions as $levels) {
            $count += count($this->flatten($levels));
        }

        return $count;
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
            if (!$this->safe()) {
                // Inside the driver commit (DBAL still reports the transaction): with the other committed events.
                $this->committed[] = $event;

                return $event;
            }
            $this->dispatchPending();

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
     * The real transaction ended: a commit marks every event it holds committed (dispatched at the next safe point,
     * never from inside the driver commit), a rollback drops them.
     */
    public function transactionEnded(object $connection, bool $committed): void
    {
        $levels = $this->transactions[$connection] ?? [];
        unset($this->transactions[$connection]);
        if ($committed) {
            $this->committed = [...$this->committed, ...$this->flatten($levels)];
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
        $this->committed    = [];
    }

    /**
     * No transaction is open, neither per the middleware nor per DBAL on the bundle's connection (DBAL still counts
     * the transaction while its driver commit runs).
     */
    private function safe(): bool
    {
        foreach ($this->transactions as $ignored) {
            return false;
        }
        $connection = $this->connection instanceof Closure ? ($this->connection)() : null;
        if ($connection instanceof SettingsEntityManager) {
            $connection = $connection->source()->getConnection();
        }

        return !$connection instanceof Connection || !$connection->isTransactionActive();
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
