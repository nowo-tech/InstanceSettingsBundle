<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Secret\SecretRevealer;

/**
 * Audit record of one reveal of a stored secret (1.15, {@see SecretRevealer::reveal()}): dispatched through the PSR-14
 * dispatcher right before the plaintext leaves the service, so a host listener can write it to its own audit trail.
 *
 * Names only, never a value (REQ-OBS-001): the event carries the section, the field, the actor and the time. A listener
 * that needs the value reads it itself.
 */
final readonly class SecretRevealedEvent
{
    /**
     * @param string $section section name
     * @param string $field secret field name (as declared by the section)
     * @param string|null $actor user identifier of the actor (null = none resolvable, e.g. `security.allow_unauthenticated`)
     * @param DateTimeImmutable $revealedAt time of the reveal
     * @param bool $stored whether a secret was stored (false = the actor was allowed to reveal, nothing was there)
     */
    public function __construct(
        public string $section,
        public string $field,
        public ?string $actor,
        public DateTimeImmutable $revealedAt,
        public bool $stored = true,
    ) {
    }

    /**
     * The `<section>.<field>` setting key of the revealed field.
     */
    public function key(): string
    {
        return $this->section . '.' . $this->field;
    }
}
