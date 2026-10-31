<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Portability;

/**
 * Outcome of an import (or of a dry run): what changed (or would change), per section / provider.
 */
final readonly class ImportResult
{
    /**
     * @param array<string, list<string>> $changes section or provider name => changed keys
     * @param bool $dryRun nothing was written
     */
    public function __construct(
        public array $changes,
        public bool $dryRun = false,
    ) {
    }

    /**
     * Whether anything changed (or would change).
     */
    public function hasChanges(): bool
    {
        foreach ($this->changes as $keys) {
            if ($keys !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Changed keys as `<section>.<key>` strings.
     *
     * @return list<string>
     */
    public function changedKeys(): array
    {
        $keys = [];
        foreach ($this->changes as $name => $changed) {
            foreach ($changed as $key) {
                $keys[] = $name . '.' . $key;
            }
        }

        return $keys;
    }
}
