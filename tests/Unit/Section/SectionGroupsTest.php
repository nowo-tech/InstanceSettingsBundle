<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Section;

use InvalidArgumentException;
use LogicException;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SectionGroups;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsGroup;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionGroupsInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 1.14: field groups of a section (declared groups, else the fields' 1.5 form groups).
 */
final class SectionGroupsTest extends TestCase
{
    public function testAGroupValidatesAndNormalisesItsDefinition(): void
    {
        $group = new SettingsGroup('colors', fields: ['a', 'b', 'a'], fieldGroups: ['colors', 'colors']);
        self::assertSame(['a', 'b'], $group->fields);
        self::assertSame(['colors'], $group->fieldGroups);
        self::assertSame('look.group.colors', $group->labelKey('look'));
        self::assertSame('my.label', (new SettingsGroup('x', 'my.label'))->labelKey('look'));
        self::assertTrue($group->claimsFieldGroup('colors'));
        self::assertTrue($group->claimsFieldGroup('colors.light'));
        self::assertFalse($group->claimsFieldGroup('colorsx'));
        self::assertFalse($group->claimsFieldGroup(null));

        $resolved = (new SettingsGroup('x', 'l', ['a'], ['g'], true, 'domain'))->withResolvedFields(['b', 'c']);
        self::assertSame(['b', 'c'], $resolved->fields);
        self::assertSame([], $resolved->fieldGroups);
        self::assertFalse($resolved->rest);
        self::assertSame(['l', 'domain'], [$resolved->label, $resolved->translationDomain]);
    }

    /**
     * @return iterable<string, array{0: callable(): mixed, 1: string}>
     */
    public static function invalidGroups(): iterable
    {
        yield 'code' => [static fn (): SettingsGroup => new SettingsGroup('Bad Code'), 'Invalid settings group code'];
        yield 'empty label' => [static fn (): SettingsGroup => new SettingsGroup('a', ''), 'must not be empty'];
        yield 'empty domain' => [static fn (): SettingsGroup => new SettingsGroup('a', translationDomain: ''), 'must not be empty'];
        yield 'field' => [static fn (): SettingsGroup => new SettingsGroup('a', fields: ['Bad']), 'invalid field name'];
        yield 'field type' => [static fn (): SettingsGroup => new SettingsGroup('a', fields: [1]), 'invalid field name']; // @phpstan-ignore argument.type
        yield 'field group' => [static fn (): SettingsGroup => new SettingsGroup('a', fieldGroups: ['a..b']), 'invalid field group'];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('invalidGroups')]
    public function testInvalidGroupsAreRefused(callable $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $build();
    }

    public function testDeclaredGroupsClaimByNameThenByFormGroupThenRest(): void
    {
        $fields = $this->fields(SettingField::string('a')->withGroup('one'), SettingField::string('b')->withGroup('two.deep'), SettingField::string('c'), SettingField::string('d')->withGroup('one'), SettingField::string('e')->withGroup('elsewhere'));
        $groups = SectionGroups::resolve($this->declaring([
            new SettingsGroup('first', fieldGroups: ['one']),
            new SettingsGroup('second', fields: ['d'], fieldGroups: ['two']),
            new SettingsGroup('empty', fields: []),
            new SettingsGroup('rest', rest: true),
        ]), $fields);

        self::assertSame(['first', 'second', 'rest'], array_map(static fn (SettingsGroup $g): string => $g->code, $groups));
        self::assertSame([['a'], ['b', 'd'], ['c', 'e']], array_map(static fn (SettingsGroup $g): array => $g->fields, $groups));
    }

    public function testUnclaimedFieldsGoToAnAutomaticOtherGroup(): void
    {
        $groups = SectionGroups::resolve($this->declaring([new SettingsGroup('first', fields: ['a'])]), $this->fields(SettingField::string('a'), SettingField::string('b')));

        self::assertCount(2, $groups);
        self::assertSame(SectionGroups::OTHER, $groups[1]->code);
        self::assertSame(['b'], $groups[1]->fields);
        self::assertSame(SectionGroups::OTHER_LABEL, $groups[1]->labelKey('x'));
        self::assertSame(NowoInstanceSettingsBundle::TRANSLATION_DOMAIN, $groups[1]->translationDomain);

        // A declared "other" group takes them instead.
        $groups = SectionGroups::resolve($this->declaring([new SettingsGroup('other', 'mine'), new SettingsGroup('first', fields: ['a'])]), $this->fields(SettingField::string('a'), SettingField::string('b')));
        self::assertSame(['other', 'first'], array_map(static fn (SettingsGroup $g): string => $g->code, $groups));
        self::assertSame('mine', $groups[0]->label);
    }

    public function testFewerThanTwoGroupsMeanStacked(): void
    {
        self::assertSame([], SectionGroups::resolve($this->declaring([]), $this->fields(SettingField::string('a'))));
        self::assertSame([], SectionGroups::resolve($this->declaring([new SettingsGroup('all', rest: true)]), $this->fields(SettingField::string('a'), SettingField::string('b'))));
        self::assertSame([], SectionGroups::resolve($this->plain(), $this->fields(SettingField::string('a'), SettingField::string('b'))));
        self::assertSame([], SectionGroups::resolve($this->plain(), $this->fields(SettingField::string('a')->withGroup('x'), SettingField::string('b')->withGroup('x'))));
    }

    public function testWithoutDeclaredGroupsTheFormGroupsAreTheTabs(): void
    {
        $groups = SectionGroups::resolve($this->plain(), $this->fields(SettingField::string('a')->withGroup('colors.light'), SettingField::string('b'), SettingField::string('c')->withGroup('colors.dark'), SettingField::string('d')->withGroup('colors.light')));

        self::assertSame(['colors.light', 'colors.dark', 'other'], array_map(static fn (SettingsGroup $g): string => $g->code, $groups));
        self::assertSame([['a', 'd'], ['c'], ['b']], array_map(static fn (SettingsGroup $g): array => $g->fields, $groups));
        self::assertSame('plain.group.colors.light', $groups[0]->labelKey('plain'));
        self::assertNull($groups[0]->translationDomain);
    }

    public function testTheRegistryValidatesDeclaredGroupsInEveryLayout(): void
    {
        // Audit MP-I2: a second rest group fails when the section definitions load, not on the first tabs page.
        SectionGroups::validate(self::plain(), self::fields(SettingField::string('a')));
        $section = new class extends AbstractSettingsSection implements SettingsSectionGroupsInterface {
            public function getName(): string
            {
                return 'twice';
            }

            public function getFields(): iterable
            {
                yield SettingField::string('a');
            }

            public function getGroups(): iterable
            {
                yield new SettingsGroup('one', rest: true);
                yield new SettingsGroup('two', rest: true);
            }
        };

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('groups "one" and "two" are both the rest group');
        (new SettingsSectionRegistry([$section]))->all();
    }

    public function testDuplicateCodesAndUnknownFieldsFailLoudly(): void
    {
        try {
            SectionGroups::resolve($this->declaring([new SettingsGroup('a'), new SettingsGroup('a')]), $this->fields(SettingField::string('x')));
            self::fail('duplicate code');
        } catch (LogicException $e) {
            self::assertStringContainsString('duplicate group code "a"', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('group "a" lists the unknown field "typo"');
        SectionGroups::resolve($this->declaring([new SettingsGroup('a', fields: ['typo'])]), $this->fields(SettingField::string('x')));
    }

    /**
     * @return array<string, SettingField>
     */
    private function fields(SettingField ...$fields): array
    {
        $map = [];
        foreach ($fields as $field) {
            $map[$field->name] = $field;
        }

        return $map;
    }

    /**
     * @param list<SettingsGroup> $groups
     */
    private function declaring(array $groups): SettingsSectionInterface
    {
        return new class($groups) extends AbstractSettingsSection implements SettingsSectionGroupsInterface {
            /**
             * @param list<SettingsGroup> $groups
             */
            public function __construct(private readonly array $groups)
            {
            }

            public function getName(): string
            {
                return 'declared';
            }

            public function getFields(): iterable
            {
                return [];
            }

            public function getGroups(): iterable
            {
                return $this->groups;
            }
        };
    }

    private function plain(): SettingsSectionInterface
    {
        return new class extends AbstractSettingsSection {
            public function getName(): string
            {
                return 'plain';
            }

            public function getFields(): iterable
            {
                return [];
            }
        };
    }
}
