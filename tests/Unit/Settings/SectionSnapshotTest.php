<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Settings;

use Nowo\InstanceSettingsBundle\Settings\SectionSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * 1.19: the token a section form carries.
 */
final class SectionSnapshotTest extends TestCase
{
    public function testTheTokenRoundTrips(): void
    {
        $snapshot = new SectionSnapshot('general', ['site_name' => '0123456789abcdef', 'api_key:expires_on' => 'fedcba9876543210']);
        $token    = $snapshot->token();

        self::assertSame('v1:site_name=0123456789abcdef,api_key:expires_on=fedcba9876543210', $token);
        $back = SectionSnapshot::fromToken('general', $token);
        self::assertNotNull($back);
        self::assertSame('general', $back->section);
        self::assertSame($snapshot->fingerprints, $back->fingerprints);
        self::assertSame('0123456789abcdef', $back->fingerprint('site_name'));
        self::assertNull($back->fingerprint('footer'));
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function malformed(): iterable
    {
        yield 'none' => [null];
        yield 'not a string' => [['v1:a=0123456789abcdef']];
        yield 'no prefix' => ['site_name=0123456789abcdef'];
        yield 'empty' => ['v1:'];
        yield 'bad pair' => ['v1:site_name=xyz'];
        yield 'upper case name' => ['v1:Site=0123456789abcdef'];
        yield 'trailing newline' => ["v1:site_name=0123456789abcdef\n"];
        yield 'too long' => ['v1:' . str_repeat('a', 65536)];
    }

    #[DataProvider('malformed')]
    public function testAMalformedTokenIsNoSnapshot(mixed $token): void
    {
        self::assertNull(SectionSnapshot::fromToken('general', $token));
    }
}
