<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Secret;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Event\SecretRevealedEvent;
use Nowo\InstanceSettingsBundle\EventListener\InstanceSettingsAccessListener;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Throwable;

use function array_keys;
use function hash;

/**
 * The refusal line of a secret reveal (1.15.1, audit N2 / N3), shared by {@see SecretRevealer} and the UI gate
 * ({@see InstanceSettingsAccessListener}) so one alerting rule sees one shape:
 * `Instance settings: secret reveal refused` with `key`, `actor`, `at` and `reason`, never a value.
 *
 * - `actor` is the user identifier of the authenticated user ({@see UserInterface::getUserIdentifier()}), the same
 *   value the reveal line and {@see SecretRevealedEvent} have carried since 1.15,
 *   or null for a caller the host cannot name (audit N2: the UI gate used to hard-code null).
 * - `key` is `<section>.<field>` only when the registry declares exactly that section and that field (byte for byte,
 *   REQ-SEC-006); anything else is the constant {@see self::KEY_UNKNOWN}, so a caller never chooses what the line says
 *   (audit N3).
 * - The line is a `warning` while the caller still has budget in the reveal rate limiter
 *   (`security.reveal_rate_limiter`), in a bucket of its own (the refusals never eat the caller's reveals), and a
 *   `debug` line past it (audit N3: the warnings of one caller are capped; a caller rotating IPs gets a budget per IP). Without a limiter nothing in
 *   the bundle is revealable (the registry fails closed), so the line of a caller the host cannot name is `debug`
 *   and a named caller keeps its `warning`.
 *
 * Logging never decides anything and never throws: the caller refuses whatever happens here (audit PJ-L1).
 *
 * Stateless (REQ-RUNTIME-002): the actor, the request and the limiter are read per call; nothing is memoised.
 *
 * @internal not covered by the backward compatibility promise; hosts read the log lines, not this class
 */
final readonly class RevealRefusalLog
{
    /** Key logged for a section or field name the registry does not declare (audit N3). */
    public const KEY_UNKNOWN = 'unknown';

    /** Error line written instead of the refusal line when the latter could not be written (audit PJ-L1). */
    public const LOG_UNLOGGED = 'Instance settings: secret reveal refusal could not be logged';

    /** Limiter bucket of the refusal lines, apart from the reveals' own bucket. */
    public const BUCKET_REFUSAL_LOG = 'refusal_log';

    /**
     * @param SettingsSectionRegistry $registry the only source of the names written to the line
     * @param AccessGuard $guard resolves the actor
     * @param LoggerInterface|null $logger receives the line (null: nothing is written and nothing is consumed)
     * @param ClockInterface|null $clock time of the line (null = the system clock)
     * @param RateLimiterFactoryInterface|null $rateLimiter `security.reveal_rate_limiter`
     * @param string $keySalt `%kernel.secret%`, salts the limiter keys (audit F5)
     * @param RequestStack|null $requestStack client IP of a caller the host cannot name (its limiter key)
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private AccessGuard $guard,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
        private ?RateLimiterFactoryInterface $rateLimiter = null,
        private string $keySalt = '',
        private ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Writes the refusal line of `$section`.`$field` with `$reason`. Never throws (audit PJ-L1): a limiter storage or a
     * logger that fails must not turn the refusal into another answer, so a failure leaves one constant-text
     * {@see self::LOG_UNLOGGED} error line through the same logger when it still works, else nothing.
     */
    public function log(string $section, string $field, string $reason): void
    {
        if (!$this->logger instanceof LoggerInterface) {
            return;
        }

        try {
            $actor = $this->actor();
            $this->logger->log($this->level($actor), SecretRevealer::LOG_REFUSED, [
                'key'    => $this->key($section, $field),
                'actor'  => $actor,
                'at'     => ($this->clock?->now() ?? (new NativeClock())->now())->format(DateTimeImmutable::ATOM),
                'reason' => $reason,
            ]);
        } catch (Throwable) {
            try {
                $this->logger->error(self::LOG_UNLOGGED, ['reason' => $reason]);
            } catch (Throwable) {
                // The logger itself is down: nothing left to write to. The caller refuses regardless.
            }
        }
    }

    /**
     * User identifier of the authenticated user, or null.
     */
    public function actor(): ?string
    {
        $user = $this->guard->user();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }

    /**
     * Limiter key of the current caller in `$bucket` ('' = the reveals' own bucket, unchanged since 1.15): the salted
     * SHA-256 of its user identifier, else of its client IP, else of `anonymous` (audit F5).
     */
    public function rateLimitKey(string $bucket = ''): string
    {
        $actor = $this->actor();
        if ($actor === null) {
            $ip    = $this->requestStack?->getMainRequest()?->getClientIp();
            $actor = $ip === null ? 'anonymous' : 'ip:' . $ip;
        }

        // The reveals' key keeps its 1.15.0 bytes (salt, NUL, actor). Any other bucket puts \1 right after the salt,
        // where the reveal key always has NUL, so no identifier can make the two inputs equal (audit PJ-I1).
        return hash('sha256', $bucket === '' ? $this->keySalt . "\0" . $actor : $this->keySalt . "\1" . $bucket . "\0" . $actor);
    }

    /**
     * `<section>.<field>` when both names are declared, byte for byte; else {@see self::KEY_UNKNOWN}.
     */
    private function key(string $section, string $field): string
    {
        foreach (array_keys($this->registry->all()) as $name) {
            if ((string) $name !== $section) {
                continue;
            }
            foreach (array_keys($this->registry->fields($section)) as $declared) {
                if ((string) $declared === $field) {
                    return $section . '.' . $field;
                }
            }
        }

        return self::KEY_UNKNOWN;
    }

    /**
     * `warning` while the caller has budget in its refusal bucket, `debug` past it; without a limiter, `warning` only
     * for a named caller.
     */
    private function level(?string $actor): string
    {
        if (!$this->rateLimiter instanceof RateLimiterFactoryInterface) {
            return $actor === null ? LogLevel::DEBUG : LogLevel::WARNING;
        }

        return $this->rateLimiter->create($this->rateLimitKey(self::BUCKET_REFUSAL_LOG))->consume()->isAccepted() ? LogLevel::WARNING : LogLevel::DEBUG;
    }
}
