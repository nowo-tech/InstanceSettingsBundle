<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Exception;

use InvalidArgumentException;

use function implode;
use function sprintf;

/**
 * One or more submitted setting values were refused (type or constraint). Messages name the fields, never the values.
 */
final class InvalidSettingValueException extends InvalidArgumentException
{
    /**
     * @param array<string, list<string>> $violations field name => messages
     */
    public function __construct(
        public readonly string $section,
        public readonly array $violations,
    ) {
        $parts = [];
        foreach ($violations as $field => $messages) {
            $parts[] = sprintf('%s.%s: %s', $section, $field, implode(' ', $messages));
        }

        parent::__construct(sprintf('Invalid instance settings: %s', implode('; ', $parts)));
    }
}
