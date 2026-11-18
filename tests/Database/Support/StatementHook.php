<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Database\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use SensitiveParameter;

/**
 * DBAL middleware of the database race tests (1.19), a service of the kernel it hooks: once its {@see HookState} is armed,
 * the first statement of the kernel's connection whose SQL matches the pattern first runs the "other request" (a peer kernel on its own
 * connection to the same database). It fires once. Works on DBAL 3 and 4 (only prepare() and query() are wrapped).
 */
final readonly class StatementHook implements Middleware
{
    public function __construct(
        private HookState $state,
    ) {
    }

    public function wrap(Driver $driver): Driver
    {
        return new class($driver, $this->state) extends AbstractDriverMiddleware {
            public function __construct(
                Driver $driver,
                private readonly HookState $hook,
            ) {
                parent::__construct($driver);
            }

            public function connect(#[SensitiveParameter] array $params): Connection
            {
                return new class(parent::connect($params), $this->hook) extends AbstractConnectionMiddleware {
                    public function __construct(
                        Connection $connection,
                        private readonly HookState $hook,
                    ) {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->hook->fire($sql);

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->hook->fire($sql);

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int
                    {
                        // 1.21: savepoint statements go through exec(). `int` fits DBAL 3 (int) and DBAL 4 (int|string).
                        $this->hook->fire($sql);

                        return (int) parent::exec($sql);
                    }
                };
            }
        };
    }
}
