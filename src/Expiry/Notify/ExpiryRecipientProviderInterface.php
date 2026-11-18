<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

/**
 * Host port of the default expiry notifier (1.21, KIT-88): who receives the reminders. The bundle has no user model,
 * so the host lists its active users holding any of `expiring_secrets.notify.roles` (Symfony Beacon: the managers and
 * the developers). Set `expiring_secrets.notify.recipient_provider` to the service id.
 *
 * Called once per reminder, at send time: read the users then, never memoize them across calls (worker mode).
 */
interface ExpiryRecipientProviderInterface
{
    /**
     * The active people holding any of `$roles`. The same id twice is sent once.
     *
     * @param list<string> $roles `expiring_secrets.notify.roles`
     *
     * @return iterable<ExpiryRecipient>
     */
    public function expiryRecipients(array $roles): iterable;
}
