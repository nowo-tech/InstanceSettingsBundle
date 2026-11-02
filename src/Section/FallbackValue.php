<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Closure;
use LogicException;
use SensitiveParameter;

/**
 * Opaque holder of a {@see SettingFallback} value: the value lives only in a closure scope, so `var_export`, array
 * casts, `json_encode`, `print_r` / `var_dump` (masked {@see self::__debugInfo()}) never show it and serialization is
 * refused.
 *
 * @internal
 */
final readonly class FallbackValue
{
    /** @var Closure(): (bool|float|int|string|null) */
    private Closure $read;

    /**
     * @param bool|float|int|string|null $value the value to hide
     */
    public function __construct(#[SensitiveParameter] bool|float|int|string|null $value)
    {
        $this->read = static fn (): bool|float|int|string|null => $value;
    }

    /**
     * The held value.
     */
    public function get(): bool|float|int|string|null
    {
        return ($this->read)();
    }

    /**
     * Nothing to show.
     *
     * @return array<string, never>
     */
    public function __debugInfo(): array
    {
        return [];
    }

    /**
     * Refused: the value may be a secret.
     *
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('A setting fallback cannot be serialized.');
    }
}
