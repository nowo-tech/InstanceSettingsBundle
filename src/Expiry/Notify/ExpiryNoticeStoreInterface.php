<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use DateTimeImmutable;

/**
 * Sent-markers of the expiry reminders (1.21, KIT-88): at most one per {@see ExpiryNoticeKey}, across workers, restarts
 * and concurrent runs. The default is the database table `instance_setting_secret_expiry_notice`
 * ({@see DoctrineExpiryNoticeStore}).
 */
interface ExpiryNoticeStoreInterface
{
    /**
     * Records the reminder as sent before it is sent. False when it already is (sent, or being sent by another run):
     * the caller must not send it. Atomic (a unique key), never a read-then-write.
     */
    public function claim(ExpiryNoticeKey $key, DateTimeImmutable $at): bool;

    /**
     * Forgets a claim whose sending failed, so the next run sends it again.
     */
    public function release(ExpiryNoticeKey $key): void;

    /**
     * Deletes the markers of expiry dates before `$expiresBefore` (the retention purge); returns how many.
     */
    public function purge(DateTimeImmutable $expiresBefore): int;
}
