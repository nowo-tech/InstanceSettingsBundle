<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Portability;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Portability\ImportResult;
use Nowo\InstanceSettingsBundle\Portability\PortabilityEnvelope;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use PHPUnit\Framework\TestCase;

final class PortabilityEnvelopeTest extends TestCase
{
    public function testHeaderAndValidation(): void
    {
        $header = PortabilityEnvelope::header('s', new DateTimeImmutable('2026-10-31T10:00:00+00:00'));
        self::assertSame(['schema' => 's', 'version' => 1, 'exported_at' => '2026-10-31T10:00:00+00:00'], $header);
        self::assertSame(1, PortabilityEnvelope::assertValid($header, 's'));
        self::assertSame(1, PortabilityEnvelope::assertValid(['schema' => 's', 'version' => '1'], 's'));

        $cases = [
            'invalid_schema'      => ['schema' => 'x', 'version' => 1],
            'invalid_version'     => ['schema' => 's', 'version' => 'one'],
            'unsupported_version' => ['schema' => 's', 'version' => 2],
            'unknown_key:extra'   => ['schema' => 's', 'version' => 1, 'extra' => true],
        ];
        foreach ($cases as $reason => $payload) {
            try {
                PortabilityEnvelope::assertValid($payload, 's');
                self::fail('Expected ' . $reason);
            } catch (PortabilityException $e) {
                self::assertSame($reason, $e->getMessage());
            }
        }

        try {
            PortabilityEnvelope::assertValid(['schema' => 's', 'version' => 0], 's');
            self::fail('Expected unsupported_version');
        } catch (PortabilityException $e) {
            self::assertSame('unsupported_version', $e->getMessage());
        }
    }

    public function testImportResult(): void
    {
        $result = new ImportResult(['mailer' => ['from'], 'appearance' => []], true);
        self::assertTrue($result->hasChanges());
        self::assertTrue($result->dryRun);
        self::assertSame(['mailer.from'], $result->changedKeys());
        self::assertFalse((new ImportResult(['mailer' => []]))->hasChanges());
    }
}
