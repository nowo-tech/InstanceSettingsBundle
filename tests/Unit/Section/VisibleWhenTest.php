<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Section;

use InvalidArgumentException;
use LogicException;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Section\SettingType;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ProviderFixtureSection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_keys;

/**
 * 1.3 `visible_when`: option shape, definition-time validation and the visibility rule.
 */
final class VisibleWhenTest extends TestCase
{
    public function testOptionIsNormalizedAndCarriedByCopies(): void
    {
        $field = SettingField::string('host')->withVisibleWhen('provider', ['smtp', 'smtp', 2, true, false]);
        self::assertSame(['field' => 'provider', 'in' => ['smtp', '2', '1', '']], $field->visibleWhen);
        self::assertSame($field->visibleWhen, $field->notPortable()->visibleWhen);
        self::assertSame($field->visibleWhen, $field->withFormOptions(['attr' => ['x' => 'y']])->visibleWhen);

        $ctor = new SettingField('host', SettingType::String, visibleWhen: ['field' => 'provider', 'in' => ['smtp']]);
        self::assertSame(['field' => 'provider', 'in' => ['smtp']], $ctor->visibleWhen);
        self::assertNull(SettingField::string('host')->visibleWhen);
    }

    public function testVisibility(): void
    {
        $field = SettingField::string('host')->withVisibleWhen('provider', ['smtp', 2]);
        self::assertTrue($field->isVisibleFor(['provider' => 'smtp']));
        self::assertTrue($field->isVisibleFor(['provider' => 2]));
        self::assertFalse($field->isVisibleFor(['provider' => 'api']));
        self::assertFalse($field->isVisibleFor([]));
        self::assertTrue(SettingField::string('always')->isVisibleFor([]));

        $flag = SettingField::string('domain')->withVisibleWhen('tracking', [true]);
        self::assertTrue($flag->isVisibleFor(['tracking' => true]));
        self::assertTrue($flag->isVisibleFor(['tracking' => '1']));
        self::assertFalse($flag->isVisibleFor(['tracking' => false]));
        self::assertFalse($flag->isVisibleFor(['tracking' => null]));
        self::assertSame('', SettingField::visibilityValue(['x']));
        self::assertSame('1.5', SettingField::visibilityValue(1.5));
    }

    /**
     * @return iterable<string, array{0: array<mixed>, 1: string}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'missing in' => [['field' => 'provider'], 'is {field'];
        yield 'extra key' => [['field' => 'provider', 'in' => ['a'], 'x' => 1], 'is {field'];
        yield 'bad field' => [['field' => 'Provider!', 'in' => ['a']], 'field name'];
        yield 'non-string field' => [['field' => 3, 'in' => ['a']], 'field name'];
        yield 'self' => [['field' => 'host', 'in' => ['a']], 'field itself'];
        yield 'empty in' => [['field' => 'provider', 'in' => []], 'at least one'];
        yield 'non-list in' => [['field' => 'provider', 'in' => 'smtp'], 'at least one'];
        yield 'non-scalar value' => [['field' => 'provider', 'in' => [['a']]], 'strings, integers'];
        yield 'float value' => [['field' => 'provider', 'in' => [1.5]], 'strings, integers'];
    }

    /**
     * @param array<mixed> $option
     */
    #[DataProvider('invalidOptions')]
    public function testInvalidOptionsAreRefused(array $option, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new SettingField('host', visibleWhen: $option);
    }

    public function testRegistryValidatesTargetsOfTheFixture(): void
    {
        $registry = new SettingsSectionRegistry([new ProviderFixtureSection()]);
        $visible  = static fn (array $values): array => array_keys(array_filter($registry->fields('transport'), static fn (SettingField $f): bool => $f->isVisibleFor($values)));
        self::assertSame(['provider', 'label', 'smtp_host', 'smtp_port', 'tracking'], $visible(['provider' => 'smtp']));
        self::assertSame(['provider', 'label', 'api_region', 'api_key', 'tracking', 'tracking_domain'], $visible(['provider' => 'api', 'tracking' => true]));
    }

    /**
     * @return iterable<string, array{0: list<SettingField>, 1: string}>
     */
    public static function invalidSections(): iterable
    {
        $provider = SettingField::choice('provider', ['a' => 'smtp', 'b' => 2]);
        yield 'unknown target' => [[SettingField::string('host')->withVisibleWhen('nope', ['x'])], 'is not a field of the section'];
        yield 'secret target' => [[SettingField::secret('key'), SettingField::string('host')->withVisibleWhen('key', ['x'])], 'is a secret'];
        yield 'chained' => [[$provider, SettingField::string('mid')->withVisibleWhen('provider', ['smtp']), SettingField::string('host')->withVisibleWhen('mid', ['x'])], 'no chains'];
        yield 'unknown choice' => [[$provider, SettingField::string('host')->withVisibleWhen('provider', ['ses'])], 'value "ses" is not a value of "provider" (smtp, 2)'];
        yield 'bool value' => [[SettingField::bool('on'), SettingField::string('host')->withVisibleWhen('on', ['yes'])], 'is not a value of "on"'];
    }

    /**
     * @param list<SettingField> $fields
     */
    #[DataProvider('invalidSections')]
    public function testRegistryRefusesInvalidTargets(array $fields, string $message): void
    {
        $registry = new SettingsSectionRegistry([$this->section($fields)]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);
        $registry->names();
    }

    public function testFreeTextAndIntChoiceTargetsAreAccepted(): void
    {
        $registry = new SettingsSectionRegistry([$this->section([
            SettingField::choice('provider', ['a' => 'smtp', 'b' => 2]),
            SettingField::string('mode'),
            SettingField::string('host')->withVisibleWhen('provider', [2, 'smtp']),
            SettingField::string('path')->withVisibleWhen('mode', ['anything']),
        ])]);
        self::assertCount(4, $registry->fields('x'));
    }

    /**
     * @param list<SettingField> $fields
     */
    private function section(array $fields): AbstractSettingsSection
    {
        return new class($fields) extends AbstractSettingsSection {
            /**
             * @param list<SettingField> $fields
             */
            public function __construct(private readonly array $fields)
            {
            }

            public function getName(): string
            {
                return 'x';
            }

            public function getFields(): iterable
            {
                return $this->fields;
            }
        };
    }
}
