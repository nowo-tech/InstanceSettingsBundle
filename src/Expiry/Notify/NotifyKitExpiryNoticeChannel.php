<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Urgency;
use Symfony\Contracts\Translation\TranslatorInterface;

use function abs;
use function hash;

/**
 * NotifyKit channel of the default expiry notifier (1.21, KIT-88; nowo-tech/notify-kit-bundle installed): one bell
 * notification per recipient, type `instance_settings.secret_expiring` or `instance_settings.secret_expired`
 * ({@see NotifyKitExpiryTypeProvider}), linking to `notify.link_route` as a local path. Its params are the translated
 * setting label, the date and the days, never the secret. NotifyKit's own dedupe key repeats the sent-marker's.
 */
final readonly class NotifyKitExpiryNoticeChannel implements ExpiryNoticeChannelInterface
{
    /** Channel name (sent-marker key). */
    public const NAME = 'notify_kit';

    /** NotifyKit type of a secret that expires soon (or today). */
    public const TYPE_EXPIRING = 'instance_settings.secret_expiring';

    /** NotifyKit type of a secret whose date has passed. */
    public const TYPE_EXPIRED = 'instance_settings.secret_expired';

    /**
     * @param string $recipientType NotifyKit recipient type of {@see ExpiryRecipient::$id} (`notify.notify_kit.recipient_type`)
     * @param TranslatorInterface|null $translator translates the setting label in the recipient's locale (null = the key)
     */
    public function __construct(
        private NotifierInterface $notifier,
        private ExpiryNoticeLinks $links,
        private string $recipientType = 'user',
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * `notify_kit`.
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * Everyone (NotifyKit addresses people by id).
     */
    public function supports(ExpiryRecipient $recipient): bool
    {
        return true;
    }

    /**
     * Records the bell notification.
     */
    public function send(SecretExpiryThresholdReachedEvent $event, ExpiryRecipient $recipient): void
    {
        $credential = $this->translator instanceof TranslatorInterface ? $this->translator->trans($event->label, [], $event->translationDomain, $recipient->locale) : $event->label;
        $this->notifier->notify(new NotificationRequest(
            type: $event->isExpired() ? self::TYPE_EXPIRED : self::TYPE_EXPIRING,
            recipients: [new RecipientRef($this->recipientType, $recipient->id)],
            actor: null,
            context: null,
            target: 'secret_expiry:' . hash('sha256', $event->key()),
            url: $this->links->link($event, $recipient, false) ?? '/',
            params: ['credential' => $credential, 'date' => $event->expiresOn, 'days' => (string) abs($event->daysLeft)],
            urgency: $event->daysLeft <= 1 ? Urgency::High : Urgency::Normal,
            dedupeKey: 'secret_expiry:' . ExpiryNoticeKey::of($event, self::NAME, $recipient)->hash(),
        ));
    }
}
