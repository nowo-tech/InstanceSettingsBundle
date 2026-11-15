<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Exception;

use Nowo\InstanceSettingsBundle\Settings\SnapshotSettingsWriterInterface;
use RuntimeException;

use function implode;
use function sprintf;

/**
 * A section form was saved over a change another editor saved since the form was opened (1.19, Beacon spec 239,
 * {@see SnapshotSettingsWriterInterface::saveSince()}): nothing was written. HTTP 409 on the bundle's routes. The message
 * names the section and the fields, never a value.
 */
final class SettingsConflictException extends RuntimeException
{
    /**
     * @param string $section the section
     * @param list<string> $fields the conflicting field names (or `<field>:expires_on`)
     */
    public function __construct(
        public readonly string $section,
        public readonly array $fields,
    ) {
        parent::__construct(sprintf('Instance settings section "%s" was changed by another request since it was opened: %s.', $section, implode(', ', $fields)));
    }
}
