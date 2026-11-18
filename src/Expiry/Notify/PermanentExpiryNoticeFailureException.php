<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use RuntimeException;

/**
 * A delivery that no retry can fix (1.21): an address the mailer refuses (RFC), a recipient the channel cannot reach.
 * Thrown by a channel, it makes {@see SecretExpiryNotifier} keep the sent-marker and log a warning instead of retrying
 * every day. The message must not carry the secret (channels never see one).
 */
final class PermanentExpiryNoticeFailureException extends RuntimeException
{
}
