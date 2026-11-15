<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Transaction;

use ArrayObject;
use DateTimeImmutable;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\Exception\DeadlockException;
use Exception;
use Nowo\InstanceSettingsBundle\Doctrine\SettingsEntityManager;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Transaction\InstanceSettingsTransactionRunner;
use Nowo\TransactionContract\TransientStorageFailure;
use RuntimeException;
use Symfony\Component\Clock\MockClock;

/**
 * 1.19 (Beacon spec 239): the bundle's own runner on its private entity manager.
 */
final class InstanceSettingsTransactionRunnerTest extends KernelTestCase
{
    public function testTheServiceIsWiredByItsClassAndItsId(): void
    {
        $kernel = $this->bootKernel();

        self::assertSame($this->container($kernel)->get(InstanceSettingsTransactionRunner::class), $this->container($kernel)->get(InstanceSettingsTransactionRunner::SERVICE_ID));
    }

    public function testRunFlushesThePrivateManagerAndCommits(): void
    {
        $kernel  = $this->bootKernel();
        $manager = $this->manager($kernel);
        $runner  = new InstanceSettingsTransactionRunner($manager);

        $result = $runner->run(static function () use ($manager): string {
            $manager->get()->persist((new SettingValue('general', 'site_name', new DateTimeImmutable()))->storeValue('Run', new DateTimeImmutable()));

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame('"Run"', $this->connection($kernel)->fetchOne("SELECT value FROM instance_setting_value WHERE name = 'site_name'"));
        self::assertFalse($this->connection($kernel)->isTransactionActive());
    }

    public function testATransientStorageErrorBecomesATransientFailureAndNothingStaysPending(): void
    {
        $kernel  = $this->bootKernel();
        $manager = $this->manager($kernel);
        $runner  = new InstanceSettingsTransactionRunner($manager, 7);
        $row     = (new SettingValue('general', 'site_name', new DateTimeImmutable()))->storeValue('Lost', new DateTimeImmutable());

        $failure = $this->failureOf(static function () use ($runner, $manager, $row): void {
            $runner->run(static function () use ($manager, $row): never {
                $manager->get()->persist($row);

                throw new DeadlockException(self::driverException(), null);
            });
        });
        self::assertSame(7, $failure->retryAfterSeconds());
        self::assertInstanceOf(DeadlockException::class, $failure->getPrevious());
        self::assertSame(TransientStorageFailure::DEFAULT_MESSAGE, $failure->getMessage());

        self::assertFalse($manager->get()->contains($row), 'audit QM-M1: nothing of the failed unit stays in the private manager');
        $manager->get()->flush();
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_value'));
    }

    public function testRunRetryingReplaysTheOutermostUnit(): void
    {
        $kernel   = $this->bootKernel();
        $clock    = new MockClock('2026-11-15 10:00:00');
        $runner   = new InstanceSettingsTransactionRunner($this->manager($kernel), 1, 1000, $clock);
        $attempts = 0;

        $result = $runner->runRetrying(static function () use (&$attempts): int {
            if (++$attempts < 3) {
                throw new TransientStorageFailure(1);
            }

            return $attempts;
        });

        self::assertSame(3, $result);
        self::assertGreaterThanOrEqual(new DateTimeImmutable('2026-11-15 10:00:00'), $clock->now(), 'full jitter: 0 to the bound');
    }

    public function testRunRetryingGivesUpAfterItsAttempts(): void
    {
        $kernel   = $this->bootKernel();
        $runner   = new InstanceSettingsTransactionRunner($this->manager($kernel), 1, 0);
        $attempts = 0;

        $this->expectException(TransientStorageFailure::class);
        try {
            $runner->runRetrying(static function () use (&$attempts): never {
                ++$attempts;

                throw new TransientStorageFailure(1);
            }, 0);
        } finally {
            self::assertSame(1, $attempts, 'at least one attempt, no more than asked');
        }
    }

    public function testRunRetryingNeverReplaysInsideACallersTransaction(): void
    {
        $kernel     = $this->bootKernel();
        $runner     = new InstanceSettingsTransactionRunner($this->manager($kernel), 1, 0);
        $connection = $this->manager($kernel)->get()->getConnection();
        /** @var ArrayObject<int, bool> $attempts */
        $attempts = new ArrayObject();

        $failure = $connection->transactional(fn (): TransientStorageFailure => $this->failureOf(static function () use ($runner, $attempts): void {
            $runner->runRetrying(static function () use ($attempts): never {
                $attempts[] = true;

                throw new TransientStorageFailure(1);
            });
        }));

        self::assertSame(1, $failure->retryAfterSeconds(), 'the nested failure reaches the caller');
        self::assertCount(1, $attempts, 'never replayed inside a transaction of the caller');
    }

    public function testADomainFailureIsRethrownUnchanged(): void
    {
        $kernel = $this->bootKernel();
        $runner = new InstanceSettingsTransactionRunner($this->manager($kernel));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refused');
        $runner->runRetrying(static function (): never {
            throw new RuntimeException('refused');
        });
    }

    /**
     * The transient failure `$call` throws.
     *
     * @param callable(): void $call
     */
    private function failureOf(callable $call): TransientStorageFailure
    {
        try {
            $call();
        } catch (TransientStorageFailure $failure) {
            return $failure;
        }

        throw new RuntimeException('No transient failure.');
    }

    private static function driverException(): DriverException
    {
        return new class('deadlock') extends Exception implements DriverException {
            public function getSQLState(): string
            {
                return '40001';
            }
        };
    }

    private function manager(InstanceSettingsTestKernel $kernel): SettingsEntityManager
    {
        $manager = $this->container($kernel)->get(SettingsEntityManager::class);
        self::assertInstanceOf(SettingsEntityManager::class, $manager);

        return $manager;
    }
}
