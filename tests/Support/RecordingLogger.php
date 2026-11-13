<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

use function json_encode;

/**
 * Logger keeping every record (level, message, context), to assert what the bundle logs — and what it never logs.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * Every record as one string (to assert that a value never appears anywhere in the log).
     */
    public function dump(): string
    {
        $out = '';
        foreach ($this->records as $record) {
            $out .= $record['level'] . ' ' . $record['message'] . ' ' . json_encode($record['context']) . "\n";
        }

        return $out;
    }
}
