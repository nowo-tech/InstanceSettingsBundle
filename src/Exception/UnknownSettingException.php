<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Exception;

use InvalidArgumentException;

use function sprintf;

/**
 * A setting key (`<section>.<field>`) or section name that no registered section declares.
 */
final class UnknownSettingException extends InvalidArgumentException
{
    /**
     * Unknown section name.
     */
    public static function section(string $section): self
    {
        return new self(sprintf('Unknown instance settings section "%s".', $section));
    }

    /**
     * Unknown field inside a known section.
     */
    public static function field(string $section, string $field): self
    {
        return new self(sprintf('Unknown instance setting "%s.%s".', $section, $field));
    }

    /**
     * A key without the `<section>.<field>` shape.
     */
    public static function malformedKey(string $key): self
    {
        return new self(sprintf('Invalid instance setting key "%s": expected "<section>.<field>".', $key));
    }
}
