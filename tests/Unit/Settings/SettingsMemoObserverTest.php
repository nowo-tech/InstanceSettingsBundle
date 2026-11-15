<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Settings\SettingsMemoObserver;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Contracts\Service\ResetInterface;

/**
 * 1.19: the settings memo is dropped by every rollback, never by a commit.
 */
final class SettingsMemoObserverTest extends TestCase
{
    public function testOnlyRollbacksDropTheMemo(): void
    {
        $memo = new class implements ResetInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
        $observer   = new SettingsMemoObserver(static fn (): ResetInterface => $memo);
        $connection = new stdClass();

        $observer->transactionStarted($connection);
        $observer->savepointCreated($connection, 'DOCTRINE_2');
        $observer->savepointReleased($connection, 'DOCTRINE_2');
        $observer->transactionEnded($connection, true);
        self::assertSame(0, $memo->resets);

        $observer->savepointRolledBack($connection, 'DOCTRINE_2');
        $observer->transactionEnded($connection, false);
        self::assertSame(2, $memo->resets);
    }

    public function testAMemoNotBuiltYetIsLeftAlone(): void
    {
        $observer = new SettingsMemoObserver(static fn (): ?ResetInterface => null);
        $observer->transactionEnded(new stdClass(), false);

        $this->addToAssertionCount(1);
    }
}
