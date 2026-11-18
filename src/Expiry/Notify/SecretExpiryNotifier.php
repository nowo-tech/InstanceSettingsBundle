<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry\Notify;

use LogicException;
use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Nowo\InstanceSettingsBundle\Expiry\SecretExpiryChecker;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function in_array;
use function preg_match;
use function sprintf;

/**
 * The default expiry notifier (1.21, KIT-88, `expiring_secrets.notify.enabled: true`): turns each
 * {@see SecretExpiryThresholdReachedEvent} of one of `notify.days` into a reminder for every recipient of
 * `notify.roles` ({@see ExpiryRecipientProviderInterface}) on every channel that reaches them (mail through MailKit,
 * the NotifyKit bell).
 *
 * At most once per secret, date, threshold, channel and recipient: each reminder is claimed in the sent-marker store
 * ({@see ExpiryNoticeStoreInterface}, a unique key) **before** it is sent, so concurrent runs, restarted workers and a
 * retried run skip what is already sent. A failed delivery releases its claim, the other recipients still get theirs,
 * and the notifier then throws {@see ExpiryNoticeFailedException}: {@see SecretExpiryChecker} keeps the threshold open
 * and the next run retries only the failed ones. The reminder names the setting (its translated label), the date and a
 * link, never the secret: the event carries none.
 *
 * Channels: the built-in ones and every host service implementing {@see ExpiryNoticeChannelInterface}.
 *
 * Stateless (worker mode, kernel reset false): recipients and markers are read on every event.
 */
final readonly class SecretExpiryNotifier
{
    /** Channel names (part of the sent-marker key, column of 32 characters). */
    private const CHANNEL_NAME = '/^[a-z][a-z0-9_]{0,31}$/D';

    /**
     * @param iterable<ExpiryNoticeChannelInterface> $channels the active channels
     * @param list<int> $days thresholds that notify (`notify.days`)
     * @param list<string> $roles roles passed to the recipient provider (`notify.roles`)
     * @param LoggerInterface|null $logger one line per failed delivery (setting, date, threshold, channel and recipient id only)
     * @param ClockInterface|null $clock `sent_at` of a marker (null = the system clock)
     */
    public function __construct(
        private ExpiryRecipientProviderInterface $recipients,
        private ExpiryNoticeStoreInterface $store,
        private iterable $channels,
        private array $days,
        private array $roles,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Sends the reminders of one threshold.
     *
     * @throws ExpiryNoticeFailedException when a delivery failed (the others are sent; the failed ones are retried)
     */
    public function __invoke(SecretExpiryThresholdReachedEvent $event): void
    {
        if (!in_array($event->threshold, $this->days, true)) {
            return;
        }
        $seen   = [];
        $failed = 0;
        foreach ($this->recipients->expiryRecipients($this->roles) as $recipient) {
            if (isset($seen[$recipient->id])) {
                continue;
            }
            $seen[$recipient->id] = true;
            foreach ($this->channels as $channel) {
                if (preg_match(self::CHANNEL_NAME, $channel->name()) !== 1) {
                    throw new LogicException(sprintf('The expiry notice channel %s must be named with [a-z0-9_] (at most 32 characters, a letter first).', $channel::class));
                }
                if (!$channel->supports($recipient)) {
                    continue;
                }
                $key = ExpiryNoticeKey::of($event, $channel->name(), $recipient);
                if (!$this->store->claim($key, ($this->clock ?? new NativeClock())->now())) {
                    // Already sent (or being sent by a concurrent run).
                    continue;
                }

                try {
                    $channel->send($event, $recipient);
                } catch (Throwable $e) {
                    $this->store->release($key);
                    ++$failed;
                    $this->logger?->error('Instance settings secret expiry reminder could not be delivered; it is retried on the next run.', [
                        'bundle'     => 'NowoInstanceSettingsBundle',
                        'setting'    => $event->key(),
                        'expires_on' => $event->expiresOn,
                        'threshold'  => $event->threshold,
                        'channel'    => $key->channel,
                        'recipient'  => $recipient->id,
                        'exception'  => $e::class,
                    ]);
                }
            }
        }
        if ($failed > 0) {
            throw new ExpiryNoticeFailedException(sprintf('%d expiry reminder(s) of %s (threshold %d) could not be delivered; they are retried on the next run.', $failed, $event->key(), $event->threshold));
        }
    }
}
