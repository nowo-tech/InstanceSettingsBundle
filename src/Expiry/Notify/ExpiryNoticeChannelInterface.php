<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;

/**
 * One way of delivering an expiry reminder (1.21, KIT-88): the mail ({@see MailExpiryNoticeChannel}) and the NotifyKit
 * bell ({@see NotifyKitExpiryNoticeChannel}). Stateless (worker mode). The event carries no secret, and a channel never
 * reads one.
 */
interface ExpiryNoticeChannelInterface
{
    /**
     * Channel name, part of the sent-marker key (`[a-z0-9_]`, at most 32 characters).
     */
    public function name(): string;

    /**
     * Whether this channel can reach `$recipient` (the mail needs an address).
     */
    public function supports(ExpiryRecipient $recipient): bool;

    /**
     * Delivers the reminder to one recipient; throws when it could not (the reminder is retried by the next run).
     */
    public function send(SecretExpiryThresholdReachedEvent $event, ExpiryRecipient $recipient): void;
}
