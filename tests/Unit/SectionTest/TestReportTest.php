<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\SectionTest;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Nowo\InstanceSettingsBundle\SectionTest\TestStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_fill;
use function mb_strlen;
use function str_repeat;

/**
 * 1.18: the checks and the report of a section test are translation keys with scalar parameters, never free text.
 */
final class TestReportTest extends TestCase
{
    public function testAChecksKeysStatusAndParameters(): void
    {
        $check = TestCheck::fail('mailer.test.smtp', 'mailer.test.hint.code', ['%code%' => 535, '{host}' => 'smtp.example.test', 'ok' => true, '%ratio%' => 0.5], 'app');

        self::assertSame(TestStatus::Fail, $check->status);
        self::assertSame(['%code%' => 535, '{host}' => 'smtp.example.test', 'ok' => true, '%ratio%' => 0.5], $check->hintParams);
        self::assertSame(['label' => 'mailer.test.smtp', 'status' => 'fail', 'hint' => 'mailer.test.hint.code', 'params' => $check->hintParams, 'domain' => 'app'], $check->toArray());
        self::assertSame(TestStatus::Ok, TestCheck::ok('a')->status);
        self::assertSame(TestStatus::Skipped, TestCheck::skipped('a')->status);
        self::assertNull(TestCheck::ok('a')->hintKey);
        self::assertSame(TestCheck::MAX_PARAM_LENGTH, mb_strlen((string) TestCheck::ok('a', 'b', ['%long%' => str_repeat('é', 500)])->hintParams['%long%']));
    }

    /**
     * @return iterable<string, array{0: callable(): TestCheck}>
     */
    public static function invalidChecks(): iterable
    {
        yield 'free text label' => [static fn (): TestCheck => TestCheck::ok('The server answered')];
        yield 'empty label' => [static fn (): TestCheck => TestCheck::ok('')];
        yield 'free text hint' => [static fn (): TestCheck => TestCheck::ok('a.b', 'is down')];
        yield 'free text domain' => [static fn (): TestCheck => TestCheck::ok('a.b', null, [], 'my domain')];
        yield 'list params' => [static fn (): TestCheck => TestCheck::ok('a.b', 'c', ['x'])];
        yield 'bad param name' => [static fn (): TestCheck => TestCheck::ok('a.b', 'c', ['%a b%' => 'x'])];
        yield 'array param' => [static fn (): TestCheck => TestCheck::ok('a.b', 'c', ['%a%' => ['x']])];
        yield 'object param' => [static fn (): TestCheck => TestCheck::ok('a.b', 'c', ['%a%' => new stdClass()])];
        yield 'null param' => [static fn (): TestCheck => TestCheck::ok('a.b', 'c', ['%a%' => null])];
        yield 'too many params' => [static fn (): TestCheck => TestCheck::ok('a.b', 'c', ['a' => 1, 'b' => 1, 'c' => 1, 'd' => 1, 'e' => 1, 'f' => 1, 'g' => 1, 'h' => 1, 'i' => 1, 'j' => 1, 'k' => 1])];
    }

    /**
     * @param callable(): TestCheck $check
     */
    #[DataProvider('invalidChecks')]
    public function testOnlyTranslationKeysAndScalarParameters(callable $check): void
    {
        $this->expectException(InvalidArgumentException::class);
        $check();
    }

    public function testTheOverallStatus(): void
    {
        self::assertSame(TestStatus::Skipped, (new TestReport())->status);
        self::assertSame([], (new TestReport())->checks);
        self::assertSame(TestStatus::Skipped, TestReport::of(TestCheck::skipped('a'))->status);
        self::assertSame(TestStatus::Ok, TestReport::of(TestCheck::skipped('a'), TestCheck::ok('b'))->status);
        self::assertSame(TestStatus::Fail, TestReport::of(TestCheck::ok('a'), TestCheck::fail('b'), TestCheck::ok('c'))->status);
        self::assertCount(3, TestReport::of(TestCheck::ok('a'), TestCheck::fail('b'), TestCheck::ok('c'))->checks);
    }

    public function testAReportHoldsChecksOnly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('holds TestCheck objects, got string');
        new TestReport(['a']);
    }

    public function testAReportHoldsAtMostTwentyChecks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TestReport(array_fill(0, TestReport::MAX_CHECKS + 1, TestCheck::ok('a')));
    }
}
