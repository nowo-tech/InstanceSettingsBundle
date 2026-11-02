<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Seed;

use function array_keys;
use function count;

/**
 * Outcome of {@see FallbackSeeder::seed()}: the `<section>.<field>` keys seeded (or that would be, in a dry run) and
 * the skipped ones with their reason. Holds names and reason codes only, never values.
 */
final readonly class SeedReport
{
    /** The field already has a stored value (never overwritten). */
    public const SKIP_STORED = 'stored';

    /** The fallback is empty (null or blank). */
    public const SKIP_EMPTY = 'empty';

    /** The section declares no such field. */
    public const SKIP_UNKNOWN_FIELD = 'unknown_field';

    /** The fallback and the field disagree on being a secret (a secret is never stored in clear). */
    public const SKIP_SECRET_MISMATCH = 'secret_mismatch';

    /** The fallback does not pass the field type / constraints (the messages are not reported: they may echo it). */
    public const SKIP_INVALID = 'invalid';

    /** A second fallback for the same field (the first one wins). */
    public const SKIP_DUPLICATE = 'duplicate';

    /**
     * @param list<string> $sections examined sections (those implementing the fallback interface)
     * @param list<string> $seeded `<section>.<field>` keys written (or that would be written in a dry run)
     * @param array<string, string> $skipped `<section>.<field>` => reason ({@see self::SKIP_STORED}, …)
     * @param list<string> $secrets the seeded keys that are secrets
     * @param bool $dryRun nothing was written
     */
    public function __construct(
        public array $sections = [],
        public array $seeded = [],
        public array $skipped = [],
        public array $secrets = [],
        public bool $dryRun = false,
    ) {
    }

    /**
     * Whether at least one key was (or would be) seeded.
     */
    public function hasChanges(): bool
    {
        return $this->seeded !== [];
    }

    /**
     * Number of keys skipped with `$reason`, or of every skipped key.
     */
    public function skippedCount(?string $reason = null): int
    {
        if ($reason === null) {
            return count($this->skipped);
        }

        return count(array_keys($this->skipped, $reason, true));
    }
}
