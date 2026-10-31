<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Event;

use Nowo\InstanceSettingsBundle\Settings\InstanceSettingsInterface;

/**
 * Dispatched after a section was saved with at least one changed field (admin form, API or import).
 *
 * Carries field names only, never values: listeners that need the new value read it from
 * {@see InstanceSettingsInterface} (for example to reset a cached mailer
 * transport or to write an audit log entry).
 */
final readonly class SettingsChangedEvent
{
    /**
     * @param string $section section name
     * @param list<string> $changedFields changed field names (plain and secret)
     * @param list<string> $secretFields the changed field names that are secrets
     * @param string $origin `form`, `import` or `api`
     */
    public function __construct(
        public string $section,
        public array $changedFields,
        public array $secretFields = [],
        public string $origin = self::ORIGIN_API,
    ) {
    }

    /** Saved from the admin section form. */
    public const ORIGIN_FORM = 'form';

    /** Saved by an import (admin page or CLI). */
    public const ORIGIN_IMPORT = 'import';

    /** Saved by application code. */
    public const ORIGIN_API = 'api';
}
