<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use DateTimeInterface;
use Nowo\UiKitBundle\Date\DateFormatterInterface;

/**
 * 1.24: a host date formatter (the reader's day-first order) aliased to UiKit's contract: the bundle's dates for people
 * follow it.
 */
final class ReaderDateFormatter implements DateFormatterInterface
{
    public function format(DateTimeInterface $date, string $kind = self::DATETIME, bool $zone = false): string
    {
        $text = match ($kind) {
            self::DATE     => $date->format('d/m/Y'),
            self::DATETIME => $date->format('d/m/Y H:i'),
            default        => $date->format('d/m/Y H:i:s'),
        };

        return 'reader ' . $text . ($zone ? ' ' . $date->format('T') : '');
    }
}
