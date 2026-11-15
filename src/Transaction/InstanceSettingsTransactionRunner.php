<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Transaction;

use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;

use function max;
use function min;
use function random_int;

/**
 * 1.19 (Beacon spec 239): the runner of every write unit of the settings store, registered as
 * `nowo_instance_settings.transaction_runner` and injected into the bundle's services by its class. The bundle never
 * runs its writes through a runner the application binds to nowo-tech/transaction-contract's
 * {@see TransactionRunnerInterface}: such a runner drives the application's entity manager (it would commit the
 * application's pending work with a settings write, and reset that manager after a settings failure).
 *
 * One attempt, one transaction (a savepoint inside a transaction the caller opened) on the bundle's **private** entity
 * manager ({@see SettingsEntityManager}), flushed inside it and committed by the runner, through
 * nowo-tech/transaction-doctrine. The application's entity manager is never flushed, cleared, closed or reset, and none
 * of its entities is detached: the private manager holds bundle rows only. After any failure:
 *
 * - no transaction of the runner stays open: transaction-doctrine rolls back to the entry level, and closes the
 *   connection only when that rollback fails (after a MySQL / MariaDB deadlock inside a nested unit the server dropped
 *   the savepoints, and `ROLLBACK TO SAVEPOINT` fails with 1305);
 * - nothing the unit persisted, changed or scheduled for deletion stays pending in the private manager (audit QM-M1):
 *   it is cleared (or detached from), and a manager the ORM closed in a failed flush is created again on its next use;
 * - a transient failure (deadlock, lock wait timeout, serialization failure, lost savepoint) surfaces as
 *   {@see TransientStorageFailure} with a Retry-After hint (503 on the bundle's routes); anything else is rethrown.
 *
 * {@see self::run()} makes one attempt (HTTP writes: the client retries after the 503). {@see self::runRetrying()} replays
 * a transient failure a few times with full-jitter backoff, only when it is the OUTERMOST transaction (console seeds,
 * replayable background units): the side effects of the unit (events, log lines, file writes) are held until the
 * outermost commit and dropped with a rolled-back attempt, so a replay never duplicates nor loses them.
 *
 * Stateless apart from the private manager it is given: one instance serves every request of a long-running worker.
 */
final readonly class InstanceSettingsTransactionRunner implements TransactionRunnerInterface
{
    /** The service id the bundle's services are wired to. */
    public const SERVICE_ID = 'nowo_instance_settings.transaction_runner';

    /** Attempts of {@see self::runRetrying()} (the first one included). */
    public const RETRY_ATTEMPTS = 3;

    /** Upper bound of the first backoff, in milliseconds; it doubles per attempt (full jitter: 0 to the bound). */
    public const BACKOFF_BASE_MILLISECONDS = 50;

    /** Upper bound of any backoff, in milliseconds. */
    public const BACKOFF_CAP_MILLISECONDS = 1000;

    /**
     * @param SettingsEntityManager $entityManager the bundle's private entity manager (resolved per call)
     * @param int|null $retryAfterSeconds Retry-After hint of the {@see TransientStorageFailure}; null for none
     * @param int $backoffBaseMilliseconds upper bound of the first backoff of {@see self::runRetrying()} (0 = no wait)
     * @param ClockInterface|null $clock waits the backoff (null = the system clock; a mock clock in tests)
     */
    public function __construct(
        private SettingsEntityManager $entityManager,
        private ?int $retryAfterSeconds = 1,
        private int $backoffBaseMilliseconds = self::BACKOFF_BASE_MILLISECONDS,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * One attempt of the unit of work in one transaction of the private manager (flushed before the commit).
     *
     * {@inheritDoc}
     */
    public function run(callable $operation): mixed
    {
        // The private manager of this call (a closed one is created again): transaction-doctrine's ORM runner without a
        // registry clears it after a storage failure, and forgets what a refused unit loaded or wrote.
        return DoctrineTransactionRunner::forEntityManager($this->entityManager->get(), null, null, $this->retryAfterSeconds)->run($operation);
    }

    /**
     * Same as {@see self::run()}, but a transient failure of the OUTERMOST transaction is replayed up to `$attempts`
     * times in all, after a full-jitter backoff. Inside a transaction the caller opened it is not replayed: the storage
     * may have rolled back the caller's work as well (a MySQL deadlock rolls back the whole transaction), so the failure
     * goes to the caller. The operation must be replayable: it loads what it changes, and its side effects wait until
     * the outermost commit.
     *
     * @template T
     *
     * @param callable(): T $operation the unit of work; replayable
     * @param int $attempts attempts in all (at least one)
     *
     * @throws TransientStorageFailure when the last attempt failed transiently, or a nested one did
     *
     * @return T what the operation returned
     */
    public function runRetrying(callable $operation, int $attempts = self::RETRY_ATTEMPTS): mixed
    {
        $attempts = max(1, $attempts);
        for ($attempt = 1;; ++$attempt) {
            $outermost = !$this->entityManager->get()->getConnection()->isTransactionActive();
            try {
                return $this->run($operation);
            } catch (TransientStorageFailure $failure) {
                if (!$outermost || $attempt >= $attempts) {
                    throw $failure;
                }
                $this->backoff($attempt);
            }
        }
    }

    /**
     * Waits a random time between 0 and the attempt's bound (full jitter: concurrent losers do not retry in lockstep). Only
     * an outermost unit waits (console seeds, background units), never inside a caller's transaction.
     */
    private function backoff(int $attempt): void
    {
        $bound = min(self::BACKOFF_CAP_MILLISECONDS, $this->backoffBaseMilliseconds << ($attempt - 1));
        if ($bound > 0) {
            ($this->clock ?? new NativeClock())->sleep(random_int(0, $bound) / 1000);
        }
    }
}
