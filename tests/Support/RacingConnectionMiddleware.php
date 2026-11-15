<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Closure;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

use function str_starts_with;

/**
 * DBAL middleware simulating a concurrent writer (audit I4): once armed with {@see self::$race}, the next transaction
 * start, savepoint or `INSERT INTO <$table>` first runs the race on the raw driver connection — the "other request"
 * that stored its row after the store read and before it wrote. The race runs once.
 */
final class RacingConnectionMiddleware implements Middleware
{
    /** @var (Closure(Connection): void)|null */
    public ?Closure $race = null;

    public int $raced = 0;

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
                            $this->middleware->fire($this->inner);
                        }

                        return parent::prepare($sql);
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
