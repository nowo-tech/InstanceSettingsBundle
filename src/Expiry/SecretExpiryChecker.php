<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Expiry;

use Nowo\InstanceSettingsBundle\Event\SecretExpiryThresholdReachedEvent;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Throwable;

use function array_filter;
use function min;
use function rsort;

/**
 * Announces the expiring secrets that reached a threshold (1.17): one {@see SecretExpiryThresholdReachedEvent} per
 * threshold per expiry date, behind `nowo:instance-settings:check-expiring-secrets` (cron / scheduler).
 *
 * For each stored date that counts ({@see ExpiringSecrets::describe()}), the thresholds crossed today are those with
 * `daysLeft <= threshold`. The smallest crossed one is announced unless it (or a smaller one) already was for this date:
 * it is first **claimed** in the store with one conditional UPDATE ({@see SecretExpiryStoreInterface::claim()}), so two
 * concurrent runs, a restart or another worker never announce it twice, then dispatched. A listener that throws releases
 * the claim (the next run retries) and the run goes on with the other fields. A new date resets the record.
 *
 * Stateless (worker-safe): "today" and the stored records are read on every run.
 */
final readonly class SecretExpiryChecker
{
    /** Default `expiring_secrets.thresholds`. */
    public const DEFAULT_THRESHOLDS = [30, 7, 1, 0];

    /** @var list<int> */
    private array $thresholds;

    /**
     * @param list<int> $thresholds days before the date (`expiring_secrets.thresholds`; 0 = the day itself and later)
     * @param EventDispatcherInterface|null $dispatcher receives the events (PSR-14)
     * @param LoggerInterface|null $logger one line per announcement / failure (names, dates and numbers only)
     * @param ClockInterface|null $clock `notified_at` of a claim (null = the system clock)
     */
    public function __construct(
        private ExpiringSecrets $secrets,
        private SecretExpiryStoreInterface $store,
        array $thresholds = self::DEFAULT_THRESHOLDS,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
    ) {
        rsort($thresholds);
        $this->thresholds = $thresholds;
    }

    /**
     * Announces every threshold due today (a dry run only lists them: nothing is recorded or dispatched).
     */
    public function check(bool $dryRun = false): SecretExpiryCheckReport
    {
        $today     = $this->secrets->today();
        $announced = [];
        $failed    = [];
        foreach ($this->store->findAll() as $record) {
            $secret = $this->secrets->describe($record, $today);
            if (!$secret instanceof ExpiringSecret) {
                continue;
            }
            $crossed = array_filter($this->thresholds, static fn (int $threshold): bool => $secret->daysLeft <= $threshold);
            if ($crossed === []) {
                continue;
            }
            $threshold = min($crossed);
            if ($record->notifiedThreshold !== null && $record->notifiedThreshold <= $threshold) {
                continue;
            }
            $event = new SecretExpiryThresholdReachedEvent($secret->section, $secret->field, $secret->label, $secret->translationDomain, $secret->expiresOnString(), $threshold, $secret->daysLeft);
            if ($dryRun) {
                $announced[] = $event;

                continue;
            }
            if (!$this->store->claim($record->section, $record->field, $record->expiresOn, $threshold, ($this->clock ?? new NativeClock())->now())) {
                // Another run announced it meanwhile.
                continue;
            }

            try {
                $this->dispatcher?->dispatch($event);
            } catch (Throwable $e) {
                $this->store->release($record->section, $record->field, $record->expiresOn, $threshold, $record->notifiedThreshold);
                $failed[] = $event->key();
                $this->logger?->error('Instance settings secret expiry reminder failed; it is retried on the next run.', $this->context($event) + ['exception' => $e::class]);

                continue;
            }
            $announced[] = $event;
            $this->logger?->info('Instance settings secret expiry announced.', $this->context($event));
        }

        return new SecretExpiryCheckReport($announced, $failed, $dryRun);
    }

    /**
     * @return array<string, int|string>
     */
    private function context(SecretExpiryThresholdReachedEvent $event): array
    {
        return [
            'bundle'     => 'NowoInstanceSettingsBundle',
            'section'    => $event->section,
            'field'      => $event->field,
            'expires_on' => $event->expiresOn,
            'threshold'  => $event->threshold,
            'days_left'  => $event->daysLeft,
        ];
    }
}
