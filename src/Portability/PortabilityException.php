<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Portability;

use InvalidArgumentException;
use Throwable;

use function explode;

/**
 * A refused export / import. The message is a stable reason code (`too_large`, `invalid_schema`,
 * `unknown_section:mailer`, `invalid_value:mailer.from`, …): it names keys, never values, so it is safe to log and to
 * map to a translated flash message.
 */
final class PortabilityException extends InvalidArgumentException
{
    /**
     * @param string $reason reason code, optionally followed by `:<key>`
     */
    public function __construct(string $reason, ?Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }

    /**
     * Reason code without the key (`invalid_value` for `invalid_value:mailer.from`).
     */
    public function reason(): string
    {
        return explode(':', $this->getMessage(), 2)[0];
    }

    /**
     * Key part of the message (`mailer.from`), or null.
     */
    public function key(): ?string
    {
        return explode(':', $this->getMessage(), 2)[1] ?? null;
    }
}
