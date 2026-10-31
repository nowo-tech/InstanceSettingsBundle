<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Section;

use LogicException;
use Nowo\InstanceSettingsBundle\Exception\UnknownSettingException;
use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use PHPUnit\Framework\TestCase;

final class SettingsSectionRegistryTest extends TestCase
{
    public function testLookups(): void
    {
        $registry = new SettingsSectionRegistry([new MailerFixtureSection(), new GeneralFixtureSection()]);

        self::assertSame(['mailer', 'general'], $registry->names());
        self::assertSame(['mailer', 'general'], array_keys($registry->all()));
        self::assertTrue($registry->has('mailer'));
        self::assertFalse($registry->has('nope'));
        self::assertInstanceOf(MailerFixtureSection::class, $registry->get('mailer'));
        self::assertSame(['from', 'from_name', 'dsn', 'test_recipient', 'enabled'], array_keys($registry->fields('mailer')));
        self::assertTrue($registry->field('mailer', 'dsn')->secret);
        [$section, $name, $field] = $registry->resolve('general.page_size');
        self::assertSame(['general', 'page_size', 20], [$section, $name, $field->default]);
    }

    public function testAbstractSectionDefaults(): void
    {
        $section = new class extends AbstractSettingsSection {
            public function getName(): string
            {
                return 'demo';
            }

            public function getFields(): iterable
            {
                return [];
            }
        };

        self::assertSame('demo.label', $section->getLabel());
        self::assertSame('messages', $section->getTranslationDomain());
        self::assertNull($section->getIntro());
        self::assertNull($section->getIcon());
    }

    public function testUnknownLookups(): void
    {
        $registry = new SettingsSectionRegistry([new MailerFixtureSection()]);

        foreach ([
            static fn (): SettingsSectionInterface => $registry->get('nope'),
            static fn (): array => $registry->fields('nope'),
            static fn (): SettingField => $registry->field('mailer', 'nope'),
            static fn (): array => $registry->resolve('mailer'),
            static fn (): array => $registry->resolve('mailer.'),
            static fn (): array => $registry->resolve('a.b.c'),
        ] as $call) {
            try {
                $call();
                self::fail('Expected UnknownSettingException');
            } catch (UnknownSettingException $e) {
                self::assertStringContainsString('instance setting', $e->getMessage());
            }
        }
    }

    public function testInvalidSectionName(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Invalid instance settings section name "Bad"');
        (new SettingsSectionRegistry([$this->section('Bad', [])]))->names();
    }

    public function testDuplicateSection(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate instance settings section "mailer"');
        (new SettingsSectionRegistry([new MailerFixtureSection(), $this->section('mailer', [])]))->names();
    }

    public function testDuplicateField(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Duplicate field "a" in instance settings section "dup"');
        (new SettingsSectionRegistry([$this->section('dup', [SettingField::string('a'), SettingField::bool('a')])]))->names();
    }

    /**
     * @param list<SettingField> $fields
     */
    private function section(string $name, array $fields): SettingsSectionInterface
    {
        return new class($name, $fields) extends AbstractSettingsSection {
            /**
             * @param list<SettingField> $fields
             */
            public function __construct(private readonly string $name, private readonly array $fields)
            {
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getFields(): iterable
            {
                return $this->fields;
            }
        };
    }
}
