<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Driver\API\ExceptionConverter;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Query;
use SensitiveParameter;

use function str_starts_with;

/**
 * DBAL middleware simulating a concurrent writer (audit I4): once armed with {@see self::$race}, the next transaction
 * start, savepoint or `INSERT INTO <$table>` first runs the race on the raw driver connection — the "other request"
 * that stored its row after the store read and before it wrote. The race runs once.
 *
 * With {@see self::$deadlockOnInsert} (audit QC-M1), the next `INSERT INTO <$table>` behaves like a MySQL deadlock
 * victim instead: the server rolls the whole transaction back (every savepoint is gone) and the statement fails with
 * error 1213, converted to {@see DeadlockException}; the next ROLLBACK is then a no-op, as on MySQL.
 */
final class RacingConnectionMiddleware implements Middleware
{
    /** MySQL error code of a deadlock victim. */
    public const DEADLOCK = 1213;

    /** @var (Closure(Connection): void)|null */
    public ?Closure $race = null;

    public int $raced = 0;

    public bool $deadlockOnInsert = false;

    /** The simulated server already rolled the transaction back: the next driver rollback is a no-op. */
    public bool $rolledBackByServer = false;

    public function __construct(
        private readonly string $table,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        $middleware = $this;

        return new class($driver, $middleware) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly RacingConnectionMiddleware $middleware,
            ) {
                parent::__construct($driver);
            }

            public function getExceptionConverter(): ExceptionConverter
            {
                $converter = parent::getExceptionConverter();

                return new class($converter) implements ExceptionConverter {
                    public function __construct(
                        private readonly ExceptionConverter $converter,
                    ) {
                    }

                    public function convert(DriverException $exception, ?Query $query): \Doctrine\DBAL\Exception\DriverException
                    {
                        return $exception->getCode() === RacingConnectionMiddleware::DEADLOCK ? new DeadlockException($exception, $query) : $this->converter->convert($exception, $query);
                    }
                };
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                $middleware = $this->middleware;

                return new class(parent::connect($params), $middleware) extends AbstractConnectionMiddleware {
                    public function __construct(
                        private readonly Connection $inner,
                        private readonly RacingConnectionMiddleware $middleware,
                    ) {
                        parent::__construct($inner);
                    }

                    public function beginTransaction(): void
                    {
                        $this->middleware->fire($this->inner);
                        parent::beginTransaction();
                    }

                    public function exec(string $sql): int|string
                    {
                        if (str_starts_with($sql, 'SAVEPOINT')) {
                            $this->middleware->fire($this->inner);
                        }

                        return parent::exec($sql);
                    }

                    public function prepare(string $sql): Statement
                    {
                        if ($this->middleware->isInsert($sql)) {
                            $this->middleware->deadlock($this->inner);
                            $this->middleware->fire($this->inner);
                        }

                        return parent::prepare($sql);
                    }

                    public function rollBack(): void
                    {
                        if ($this->middleware->rolledBackByServer) {
                            $this->middleware->rolledBackByServer = false;

                            return;
                        }
                        parent::rollBack();
                    }
                };
            }
        };
    }

    /**
     * Whether `$sql` inserts into the raced table.
     */
    public function isInsert(string $sql): bool
    {
        return str_starts_with($sql, 'INSERT INTO ' . $this->table . ' ');
    }

    /**
     * Fails the statement as a MySQL deadlock victim when armed (once).
     */
    public function deadlock(Connection $connection): void
    {
        if (!$this->deadlockOnInsert) {
            return;
        }
        $this->deadlockOnInsert = false;
        try {
            // Outside a transaction there is nothing to roll back (the driver refuses).
            $connection->rollBack();
            $this->rolledBackByServer = true;
        } catch (DriverException) {
        }

        throw new class('Deadlock found when trying to get lock; try restarting transaction', '40001', self::DEADLOCK) extends AbstractException {
        };
    }

    /**
     * Runs the armed race once.
     */
    public function fire(Connection $connection): void
    {
        $race       = $this->race;
        $this->race = null;
        if ($race instanceof Closure) {
            ++$this->raced;
            $race($connection);
        }
    }
}
