<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Settings;

use function count;
use function explode;
use function implode;
use function is_string;
use function preg_match;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * What a section looked like when an editor opened it (1.19, Beacon spec 239): one fingerprint per field (and per expiry
 * date of an expiring secret), carried by the section form as a hidden token and compared, under the section's write
 * lock, with the stored state when the form is saved ({@see SnapshotSettingsWriterInterface::saveSince()}).
 *
 * A fingerprint is a keyed hash (HMAC-SHA-256 with `%kernel.secret%`, 16 hex characters) of what the form showed: never a
 * value, and never a hash of a secret that can be guessed offline. The token is not trusted for anything else: a tampered
 * token can only make the editor's own save refuse (409) or overwrite like a plain save does.
 */
final readonly class SectionSnapshot
{
    /** Token prefix (format version). */
    public const PREFIX = 'v1:';

    /** One `name=fingerprint` pair of the token. */
    private const PAIR = '/\A([a-z][a-z0-9_]{0,63}(?::expires_on)?)=([0-9a-f]{16})\z/D';

    /**
     * @param string $section the section
     * @param array<string, string> $fingerprints field name (or `<field>:expires_on`) => fingerprint
     */
    public function __construct(
        public string $section,
        public array $fingerprints,
    ) {
    }

    /**
     * The token the form carries.
     */
    public function token(): string
    {
        $pairs = [];
        foreach ($this->fingerprints as $key => $fingerprint) {
            $pairs[] = $key . '=' . $fingerprint;
        }

        return self::PREFIX . implode(',', $pairs);
    }

    /**
     * The snapshot of a submitted token, or null when there is none or it is malformed (the save then behaves as a plain
     * save, as with a host template that does not render the token).
     */
    public static function fromToken(string $section, mixed $token): ?self
    {
        if (!is_string($token) || !str_starts_with($token, self::PREFIX) || strlen($token) > 65536) {
            return null;
        }
        $body         = substr($token, strlen(self::PREFIX));
        $fingerprints = [];
        foreach ($body === '' ? [] : explode(',', $body) as $pair) {
            if (preg_match(self::PAIR, $pair, $match) !== 1) {
                return null;
            }
            $fingerprints[$match[1]] = $match[2];
        }

        return count($fingerprints) === 0 ? null : new self($section, $fingerprints);
    }

    /**
     * The fingerprint of a field (or `<field>:expires_on`) when the editor opened the section; null when unknown.
     */
    public function fingerprint(string $key): ?string
    {
        return $this->fingerprints[$key] ?? null;
    }
}
