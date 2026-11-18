<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use RuntimeException;

/**
 * Some reminders of one expiry threshold could not be delivered (1.21, KIT-88). Thrown by {@see SecretExpiryNotifier}
 * after it tried every recipient, so `nowo:instance-settings:check-expiring-secrets` keeps the threshold open and the
 * next run retries; the reminders already delivered are not sent again (their sent-markers stay). The message names
 * the setting and counts only.
 */
final class ExpiryNoticeFailedException extends RuntimeException
{
}
