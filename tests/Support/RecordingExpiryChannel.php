<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryNoticeChannelInterface;
use Nowo\InstanceSettingsBundle\Expiry\Notify\ExpiryRecipient;
use RuntimeException;

use function in_array;

/**
 * Expiry notifier channel recording what it delivers (unit tests); fails for the recipient ids in {@see self::$failFor}
 * and skips those without an e-mail when {@see self::$needsEmail}.
 */
final class RecordingExpiryChannel implements ExpiryNoticeChannelInterface
{
    /** @var list<string> "recipient:section.field:threshold" */
    public array $sent = [];

    /** @var list<string> recipient ids whose delivery throws */
    public array $failFor = [];

    public function __construct(
        private readonly string $name = 'recording',
        private readonly bool $needsEmail = false,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function supports(ExpiryRecipient $recipient): bool
    {
        return !$this->needsEmail || $recipient->email !== null;
    }

    public function send(SecretExpiryThresholdReachedEvent $event, ExpiryRecipient $recipient): void
    {
        if (in_array($recipient->id, $this->failFor, true)) {
            throw new RuntimeException('SMTP down for ' . $recipient->id);
        }
        $this->sent[] = $recipient->id . ':' . $event->key() . ':' . $event->threshold;
    }
}
