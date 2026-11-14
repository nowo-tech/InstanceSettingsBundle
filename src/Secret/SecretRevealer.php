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
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function array_keys;
use function max;
use function mb_check_encoding;

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
 * The refusal line is written by {@see RevealRefusalLog} (1.15.1, audit N2 / N3): it names the actor, logs only the
 * declared names (anything else is `unknown`) and is throttled per caller by the same rate limiter, in a bucket of its
 * own.
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

    /** Refusal reason: the stored secret is not UTF-8 text, so it cannot be delivered (audit F2). */
    public const REASON_NOT_TEXT = 'not_text';

    /** Writes the refusal lines (1.15.1). */
    private RevealRefusalLog $refusals;

    /**
     * @param SettingsSectionRegistry $registry declared sections and fields (the only source of names)
     * @param InstanceSettingsInterface $settings reads the decrypted secret
     * @param AccessGuard $guard current user and the access checker
     * @param EventDispatcherInterface|null $dispatcher receives the {@see SecretRevealedEvent} audit record
     * @param LoggerInterface|null $logger receives one line per reveal and per refusal (names only, never a value)
     * @param ClockInterface|null $clock time written to the audit record (null = the system clock)
     * @param RateLimiterFactoryInterface|null $rateLimiter `security.reveal_rate_limiter` (required as soon as a field
     *                                                      declares `revealable: true`; see
     *                                                      {@see SettingsSectionRegistry}, which fails closed without it)
     * @param string $keySalt `%kernel.secret%`: salts the rate limiter key, so the stored key is not a plain hash of a
     *                        user identifier that a known user list could be matched against (audit F5)
     * @param RequestStack|null $requestStack client IP of the current request: the limiter key of a caller the host
     *                                        cannot name (`allow_unauthenticated`, a custom checker without a Symfony
     *                                        user), so such callers do not share one bucket (audit F5)
     * @param RevealRefusalLog|null $refusalLog writes the refusal lines (1.15.1; null = one built from the arguments above)
     */
    public function __construct(
        private SettingsSectionRegistry $registry,
        private InstanceSettingsInterface $settings,
        private AccessGuard $guard,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?LoggerInterface $logger = null,
        private ?ClockInterface $clock = null,
        private ?RateLimiterFactoryInterface $rateLimiter = null,
        string $keySalt = '',
        ?RequestStack $requestStack = null,
        ?RevealRefusalLog $refusalLog = null,
    ) {
        $this->refusals = $refusalLog ?? new RevealRefusalLog($registry, $guard, $logger, $clock, $rateLimiter, $keySalt, $requestStack);
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
     * @throws ConflictHttpException the stored secret is not UTF-8 text, so it cannot be delivered as text (audit F2);
     *                               nothing is handed over and the audit trail records the refusal, not a reveal
     */
    public function reveal(string $section, string $field): ?string
    {
        $this->assertRevealable($section, $field);
        $this->consumeRateLimit($section, $field);

        $value = $this->settings->getSecret($section . '.' . $field);
        if ($value !== null && !mb_check_encoding($value, 'UTF-8')) {
            // Audit F2: a secret that is not text cannot be delivered. Refusing is the only honest answer: substituting
            // or escaping bytes would hand over a silently mangled secret, and the audit entry would claim a reveal
            // the caller never received. The message and the log name the field, never a byte of the value.
            try {
                $this->refuse($section, $field, self::REASON_NOT_TEXT);
            } finally {
                throw new ConflictHttpException('The stored secret is not UTF-8 text and cannot be revealed.');
            }
        }
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
            try {
                $this->refuse($section, $field, self::REASON_FORBIDDEN);
            } finally {
                $this->guard->deny('Access denied to revealing this instance settings secret.');
            }
        }
        if (!$this->isRevealable($section, $field)) {
            try {
                $this->refuse($section, $field, self::REASON_NOT_REVEALABLE);
            } finally {
                throw new NotFoundHttpException('Unknown revealable secret field.');
            }
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
        $limit = $this->rateLimiter->create($this->rateLimitKey())->consume();
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - $this->now()->getTimestamp());

            try {
                $this->refuse($section, $field, self::REASON_RATE_LIMITED);
            } finally {
                throw new TooManyRequestsHttpException($retryAfter, 'Too many secret reveals.');
            }
        }
    }

    /**
     * Rate limiter key of the current caller: the salted SHA-256 of its user identifier, so the limiter storage holds
     * no user identifier and no plain hash of one (audit F5). A caller the host cannot name falls back to its client
     * IP, so such callers are not all in one bucket (where a single caller would deny the feature to everybody); with
     * neither, a reveal is only possible for a host that names nobody at all, and the single `anonymous` bucket is the
     * honest answer.
     */
    private function rateLimitKey(): string
    {
        return $this->refusals->rateLimitKey();
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
     * Logs a refusal (declared names, actor and reason only; throttled per caller).
     */
    private function refuse(string $section, string $field, string $reason): void
    {
        $this->refusals->log($section, $field, $reason);
    }

    /**
     * User identifier of the actor, or null.
     */
    private function actor(): ?string
    {
        return $this->refusals->actor();
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? (new NativeClock())->now();
    }
}
