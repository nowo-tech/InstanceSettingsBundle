<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;

use function hash;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * Identity of one expiry reminder (1.21, KIT-88): secret (`section.field`), expiry date, threshold, channel and
 * recipient. A new date (renewed secret) is a new reminder; the same threshold for the same date never is.
 */
final readonly class ExpiryNoticeKey
{
    /**
     * @param string $section section name
     * @param string $field field name inside the section
     * @param string $expiresOn expiry date, `YYYY-MM-DD`
     * @param int $threshold threshold (days before the date)
     * @param string $channel channel name
     * @param string $recipient recipient id
     */
    public function __construct(
        public string $section,
        public string $field,
        public string $expiresOn,
        public int $threshold,
        public string $channel,
        public string $recipient,
    ) {
    }

    /**
     * The key of `$recipient` on `$channel` for the reminder `$event`.
     */
    public static function of(SecretExpiryThresholdReachedEvent $event, string $channel, ExpiryRecipient $recipient): self
    {
        return new self($event->section, $event->field, $event->expiresOn, $event->threshold, $channel, $recipient->id);
    }

    /**
     * SHA-256 (hex) of the six values, unambiguous whatever they contain: the primary key of the sent-marker.
     */
    public function hash(): string
    {
        return hash('sha256', json_encode([$this->section, $this->field, $this->expiresOn, $this->threshold, $this->channel, $this->recipient], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
