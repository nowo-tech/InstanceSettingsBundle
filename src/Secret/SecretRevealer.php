<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Secret;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Event\SecretRevealedEvent;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_keys;
use function hash;
use function max;

/**
 * Reads one stored secret back for a user who may reveal it (1.15, owner decision of 2026-11-13: an explicit Reveal
 * action instead of a show / hide eye).
 *
 * Every call is one field: the plaintext of the single named field, never a payload of several secrets. The checks, in
 * order, are
 *
 * 1. the UI gate, the section's own area and {@see InstanceSettingsAccessCheckerInterface::AREA_REVEAL}
 *    (`security.reveal_roles`, `ROLE_DEVELOPER` by default) — the role check lives here, on the server, never in the
 *    template: a reveal control is rendered only as a button, the plaintext is never in the page;
 * 2. the field must be declared by that section, be a secret and carry `revealable: true`
 *    ({@see SettingField::secret()}) — a field the host did not opt in is refused even when asked for directly.
 *    The section and field names coming from the client are compared byte for byte (`===`) against the declared names
 *    before any lookup (REQ-SEC-006), so a look-alike name can never reach another field;
 * 3. the rate limiter, when the host configured one (`security.reveal_rate_limiter`).
 *
 * Every allowed reveal writes an audit entry: a {@see SecretRevealedEvent} (PSR-14) and a log line, both naming the
 * section, the field, the actor and the time, never the value (REQ-OBS-001). A refusal is logged the same way, with
 * its reason.
 *
 * Stateless (REQ-RUNTIME-002): nothing about a reveal is kept on the service between requests — no memo of the value,
 * of the permission or of the limiter; the rate limiter is built per call from its factory (its state lives in the
 * host's storage, not here).
 */
final readonly class SecretRevealer
{
    /** Log message of an allowed reveal (names only). */
    public const LOG_REVEALED = 'Instance settings: secret revealed';

    /** Log message of a refused reveal (names and reason only). */
    public const LOG_REFUSED = 'Instance settings: secret reveal refused';

    /** Refusal reason: the user may not reveal (UI gate, section area or reveal area). */
    public const REASON_FORBIDDEN = 'forbidden';

    /** Refusal reason: unknown section / field, not a secret, or `revealable: false`. */
    public const REASON_NOT_REVEALABLE = 'not_revealable';

    /** Refusal reason: the rate limiter refused the call. */
    public const REASON_RATE_LIMITED = 'rate_limited';

    /**
     * @param SettingsSectionRegistry $registry declared sections and fields (the only source of names)
     * @param InstanceSettingsInterface $settings reads the decrypted secret
     * @param AccessGuard $guard current user and the access checker
     * @param EventDispatcherInterface|null $dispatcher receives the {@see SecretRevealedEvent} audit record
     * @param LoggerInterface|null $logger receives one line per reveal and per refusal (names only, never a value)
     * @param ClockInterface|null $clock time written to the audit record (null = the system clock)
     * @param RateLimiterFactoryInterface|null $rateLimiter `security.reveal_rate_limiter` (null = no limit)
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private InstanceSettingsInterface $settings,
        private AccessGuard $guard,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
        private ?RateLimiterFactoryInterface $rateLimiter = null,
    ) {
    }

    /**
     * Whether the current user may reveal a secret of `$section`: the UI gate, the section's area and the reveal area.
     */
    public function canReveal(string $section): bool
    {
        return $this->guard->isGranted() && $this->guard->isGranted($section) && $this->guard->isGranted(InstanceSettingsAccessCheckerInterface::AREA_REVEAL);
    }

    /**
     * The declared revealable secret fields of `$section`, in declaration order (empty for an unknown section).
     *
     * @return list<string>
     */
    public function revealableFields(string $section): array
    {
        $fields = [];
        foreach ($this->declaredFields($section) as $name => $field) {
            if ($field->secret && $field->revealable) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    /**
     * Plaintext of the stored secret of `$section`.`$field`, or null when the field is revealable but nothing is
     * stored. Writes the audit entry before returning.
     *
     * @throws AccessDeniedException|AccessDeniedHttpException the current user may not reveal this secret (a Security
     *                                                         exception inside an enabled firewall, else a plain 403)
     * @throws NotFoundHttpException the field is not a declared revealable secret of that section
     * @throws TooManyRequestsHttpException the rate limiter refused this call
     */
    public function reveal(string $section, string $field): ?string
    {
        $this->assertRevealable($section, $field);
        $this->consumeRateLimit($section, $field);

        $value = $this->settings->getSecret($section . '.' . $field);
        $this->audit($section, $field, $value !== null);

        return $value;
    }

    /**
     * Refuses, with an audited log line, unless the current user may reveal `$section`.`$field`: the roles first, then
     * the field's own `revealable: true`. {@see self::reveal()} runs it; an endpoint that checks something of its own
     * (the CSRF token of the admin UI) calls it first, so an attempt without the roles is refused and audited whatever
     * the rest of the request carries.
     *
     * @throws AccessDeniedException|AccessDeniedHttpException the current user may not reveal this secret
     * @throws NotFoundHttpException the field is not a declared revealable secret of that section
     */
    public function assertRevealable(string $section, string $field): void
    {
        if (!$this->canReveal($section)) {
            $this->refuse($section, $field, self::REASON_FORBIDDEN);
            $this->guard->deny('Access denied to revealing this instance settings secret.');
        }
        if (!$this->isRevealable($section, $field)) {
            $this->refuse($section, $field, self::REASON_NOT_REVEALABLE);

            throw new NotFoundHttpException('Unknown revealable secret field.');
        }
    }

    /**
     * Whether `$field` is a declared secret field of `$section` carrying `revealable: true`. The names are matched byte
     * for byte against the declared ones (REQ-SEC-006).
     */
    public function isRevealable(string $section, string $field): bool
    {
        foreach ($this->declaredFields($section) as $name => $definition) {
            if ($name === $field) {
                return $definition->secret && $definition->revealable;
            }
        }

        return false;
    }

    /**
     * Fields of the section whose declared name is exactly `$section` (REQ-SEC-006: the comparison is `===` on the
     * declared names, before any array lookup), else none.
     *
     * @return array<string, SettingField>
     */
    private function declaredFields(string $section): array
    {
        foreach (array_keys($this->registry->all()) as $name) {
            if ((string) $name === $section) {
                return $this->registry->fields($section);
            }
        }

        return [];
    }

    /**
     * Consumes one token of the configured rate limiter, keyed by the actor (its identifier hashed, so no user
     * identifier reaches the limiter storage). Without a limiter the call passes.
     *
     * @throws TooManyRequestsHttpException
     */
    private function consumeRateLimit(string $section, string $field): void
    {
        if (!$this->rateLimiter instanceof RateLimiterFactoryInterface) {
            return;
        }
        $limit = $this->rateLimiter->create(hash('sha256', (string) $this->actor()))->consume();
        if (!$limit->isAccepted()) {
            $this->refuse($section, $field, self::REASON_RATE_LIMITED);

            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - $this->now()->getTimestamp()), 'Too many secret reveals.');
        }
    }

    /**
     * Dispatches the {@see SecretRevealedEvent} and logs the reveal (names, actor and time only).
     */
    private function audit(string $section, string $field, bool $stored): void
    {
        $event = new SecretRevealedEvent($section, $field, $this->actor(), $this->now(), $stored);
        $this->logger?->info(self::LOG_REVEALED, [
            'key'    => $event->key(),
            'actor'  => $event->actor,
            'at'     => $event->revealedAt->format(DateTimeImmutable::ATOM),
            'stored' => $stored,
        ]);
        $this->dispatcher?->dispatch($event);
    }

    /**
     * Logs a refusal (names and reason only).
     */
    private function refuse(string $section, string $field, string $reason): void
    {
        $this->logger?->warning(self::LOG_REFUSED, [
            'key'    => $section . '.' . $field,
            'actor'  => $this->actor(),
            'at'     => $this->now()->format(DateTimeImmutable::ATOM),
            'reason' => $reason,
        ]);
    }

    /**
     * User identifier of the actor, or null.
     */
    private function actor(): ?string
    {
        $user = $this->guard->user();

        return $user instanceof UserInterface ? $user->getUserIdentifier() : null;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? (new NativeClock())->now();
    }
}
