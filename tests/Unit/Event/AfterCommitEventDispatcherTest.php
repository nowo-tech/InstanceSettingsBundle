<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Event;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;
use Nowo\InstanceSettingsBundle\Doctrine\FileStoreTransactionMiddleware;
use Nowo\InstanceSettingsBundle\Doctrine\TransactionObserverInterface;
use Nowo\InstanceSettingsBundle\Event\AfterCommitEventDispatcher;
use Nowo\InstanceSettingsBundle\Event\SettingsChangedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function gc_collect_cycles;

/**
 * 1.10 (DDD P2, pattern 4): SettingsChangedEvent after the outermost commit, dropped on rollback.
 */
final class AfterCommitEventDispatcherTest extends TestCase
{
    /** @var list<string> */
    private array $seen = [];

    private EventDispatcher $inner;

    private AfterCommitEventDispatcher $dispatcher;

    protected function setUp(): void
    {
        $this->inner = new EventDispatcher();
        $this->inner->addListener(SettingsChangedEvent::class, function (SettingsChangedEvent $event): void {
            $this->seen[] = $event->section;
        });
        $this->dispatcher = new AfterCommitEventDispatcher($this->inner);
    }

    public function testWithoutATransactionTheEventIsDispatchedAtOnce(): void
    {
        $event = new SettingsChangedEvent('general', ['site_name']);

        self::assertSame($event, $this->dispatcher->dispatch($event));
        self::assertSame(['general'], $this->seen);
    }

    public function testHeldUntilTheCommitAndDroppedOnRollback(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('rolled_back', ['a']));
        $connection->rollBack();
        self::assertSame([], $this->seen);

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('general', ['a']));
        self::assertSame([], $this->seen, 'not before the commit');
        $connection->commit();
        self::assertSame(['general'], $this->seen);

        $this->dispatcher->dispatch(new SettingsChangedEvent('after', ['a']));
        self::assertSame(['general', 'after'], $this->seen, 'no transaction left open');
    }

    public function testSavepointsAreFollowed(): void
    {
        $connection = $this->connection();

        // Savepoint rolled back, outer committed: only the outer event.
        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('outer', ['a']));
        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('inner', ['a']));
        $connection->rollBack();
        $connection->commit();
        self::assertSame(['outer'], $this->seen);

        // Savepoint released, outer rolled back: nothing.
        $this->seen = [];
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('inner', ['a']));
        $connection->commit();
        $connection->rollBack();
        self::assertSame([], $this->seen);

        // Savepoint released, outer committed: once, after the outer commit.
        $connection->beginTransaction();
        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('inner', ['a']));
        $connection->commit();
        self::assertSame([], $this->seen, 'a savepoint is not the real commit');
        $connection->commit();
        self::assertSame(['inner'], $this->seen);
    }

    /**
     * Audit KI-I-L1: a connection closed in the middle of a transaction (no rollback) does not hold later dispatches.
     */
    public function testAConnectionClosedMidTransactionDoesNotHoldLaterEvents(): void
    {
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('lost', ['a']));
        $connection->close();
        gc_collect_cycles();

        $this->dispatcher->dispatch(new SettingsChangedEvent('after_close', ['a']));
        self::assertSame(['after_close'], $this->seen, 'immediate: the closed transaction is gone');

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('reconnected', ['a']));
        $connection->commit();
        self::assertSame(['after_close', 'reconnected'], $this->seen);
    }

    /**
     * Audit KI-I-L2: an observer that throws does not keep the dispatcher from being told about the commit.
     */
    public function testAThrowingObserverDoesNotStopTheOthers(): void
    {
        $failing = new class implements TransactionObserverInterface {
            public function transactionStarted(object $connection): void
            {
            }

            public function transactionEnded(object $connection, bool $committed): void
            {
                throw new RuntimeException('file write failed after the commit');
            }

            public function savepointCreated(object $connection, string $name): void
            {
                throw new RuntimeException('savepoint observer failed');
            }

            public function savepointReleased(object $connection, string $name): void
            {
            }

            public function savepointRolledBack(object $connection, string $name): void
            {
            }
        };
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new FileStoreTransactionMiddleware($failing, $this->dispatcher)]));

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('general', ['a']));
        try {
            $connection->commit();
            self::fail('Expected the observer failure.');
        } catch (RuntimeException $e) {
            self::assertSame('file write failed after the commit', $e->getMessage());
        }
        self::assertSame(['general'], $this->seen, 'the dispatcher was told although the first observer threw');

        $this->dispatcher->dispatch(new SettingsChangedEvent('after', ['a']));
        self::assertSame(['general', 'after'], $this->seen, 'no stale transaction left');
    }

    public function testSavepointNotificationsWithoutATransactionAreIgnored(): void
    {
        $connection = new class {};
        $this->dispatcher->savepointCreated($connection, 'DOCTRINE_2');
        $this->dispatcher->savepointReleased($connection, 'DOCTRINE_2');
        $this->dispatcher->savepointRolledBack($connection, 'DOCTRINE_2');
        $this->dispatcher->transactionEnded($connection, true);

        $this->dispatcher->dispatch(new SettingsChangedEvent('general', ['a']));
        self::assertSame(['general'], $this->seen);
    }

    public function testASameNameSavepointClosesTheOpenOne(): void
    {
        $connection = new class {};
        $this->dispatcher->transactionStarted($connection);
        $this->dispatcher->savepointCreated($connection, 'SP');
        $this->dispatcher->dispatch(new SettingsChangedEvent('first', ['a']));
        $this->dispatcher->savepointCreated($connection, 'sp');
        $this->dispatcher->dispatch(new SettingsChangedEvent('second', ['a']));
        $this->dispatcher->savepointRolledBack($connection, 'SP');
        $this->dispatcher->transactionEnded($connection, true);

        self::assertSame(['first'], $this->seen, 'the first level was implicitly committed into its parent');
    }

    public function testAFailingListenerAfterTheCommitIsLoggedAndTheOthersStillRun(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, string, array<mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $level, (string) $message, $context];
            }
        };
        $this->dispatcher = new AfterCommitEventDispatcher($this->inner, $logger);
        $this->inner->addListener(SettingsChangedEvent::class, static function (SettingsChangedEvent $event): void {
            if ($event->section === 'broken') {
                throw new RuntimeException('listener failed with a secret detail');
            }
        }, -10);
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->dispatcher->dispatch(new SettingsChangedEvent('broken', ['a']));
        $this->dispatcher->dispatch(new SettingsChangedEvent('general', ['a']));
        $connection->commit();

        self::assertSame(['broken', 'general'], $this->seen);
        self::assertCount(1, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame(['bundle' => 'NowoInstanceSettingsBundle', 'event' => SettingsChangedEvent::class, 'exception' => RuntimeException::class], $logger->records[0][2]);

        // Immediate dispatch keeps propagating listener errors (unchanged behaviour).
        $this->expectException(RuntimeException::class);
        $this->dispatcher->dispatch(new SettingsChangedEvent('broken', ['a']));
    }

    public function testResetDropsPendingEventsAndANullDispatcherIsANoOp(): void
    {
        $connection = new class {};
        $this->dispatcher->transactionStarted($connection);
        $this->dispatcher->dispatch(new SettingsChangedEvent('pending', ['a']));
        $this->dispatcher->reset();
        $this->dispatcher->transactionEnded($connection, true);
        self::assertSame([], $this->seen);

        $silent = new AfterCommitEventDispatcher();
        $event  = new SettingsChangedEvent('general', ['a']);
        self::assertSame($event, $silent->dispatch($event));
        $silent->transactionStarted($connection);
        $silent->dispatch($event);
        $silent->transactionEnded($connection, true);
    }

    private function connection(): DbalConnection
    {
        return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], (new Configuration())->setMiddlewares([new FileStoreTransactionMiddleware($this->dispatcher)]));
    }
}
