<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\SectionTest;

use LogicException;
use Nowo\InstanceSettingsBundle\Section\SettingsGroup;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTesterRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\CalendarOAuthFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\CalendarSectionTester;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\GoogleGroupTester;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * 1.18: which section and group has a tester; declarations are checked against the sections on first use.
 */
final class SectionTesterRegistryTest extends TestCase
{
    public function testDeclaredTesters(): void
    {
        $registry = $this->registry([
            ['section' => 'calendar_oauth', 'group' => 'google', 'id' => 'google'],
            ['section' => 'calendar_oauth', 'group' => null, 'id' => 'section'],
        ]);

        self::assertFalse($registry->isEmpty());
        self::assertSame(['google', null], $registry->groupsOf('calendar_oauth'));
        self::assertSame([], $registry->groupsOf('general'));
        self::assertTrue($registry->has('calendar_oauth', null));
        self::assertTrue($registry->has('calendar_oauth', 'google'));
        self::assertFalse($registry->has('calendar_oauth', 'microsoft'));
        self::assertFalse($registry->has('calendar_oauth', 'Google'));
        self::assertInstanceOf(GoogleGroupTester::class, $registry->get('calendar_oauth', 'google'));
        self::assertInstanceOf(CalendarSectionTester::class, $registry->get('calendar_oauth', null));
        self::assertNull($registry->get('calendar_oauth', 'microsoft'));

        $group = $registry->group('calendar_oauth', 'google');
        self::assertInstanceOf(SettingsGroup::class, $group);
        self::assertSame(['mode', 'google_client_id', 'google_client_secret'], $group->fields, 'the resolved fields (rest group included)');
        self::assertNull($registry->group('calendar_oauth', null));
        self::assertNull($registry->group('calendar_oauth', 'nope'));
        self::assertNull($registry->group('general', 'nope'), 'a section without groups');
    }

    public function testWithoutTesters(): void
    {
        $registry = new SectionTesterRegistry([], null, new SettingsSectionRegistry([new CalendarOAuthFixtureSection()]));

        self::assertTrue($registry->isEmpty());
        self::assertFalse($registry->has('calendar_oauth', null));
        self::assertNull($registry->get('calendar_oauth', null));
    }

    public function testAServiceThatIsNotATesterFailsClosed(): void
    {
        $registry = new SectionTesterRegistry([['section' => 'calendar_oauth', 'group' => null, 'id' => 'odd']], new ServiceLocator(['odd' => static fn (): stdClass => new stdClass()]), new SettingsSectionRegistry([new CalendarOAuthFixtureSection()]));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must implement');
        $registry->get('calendar_oauth', null);
    }

    public function testAnUnknownSectionFailsClosed(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('tests the settings section "billing", which is not registered');
        $this->registry([['section' => 'billing', 'group' => null, 'id' => 'section']])->has('calendar_oauth', null);
    }

    public function testAnUnknownGroupFailsClosed(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('has no such group (known: "google", "microsoft")');
        $this->registry([['section' => 'calendar_oauth', 'group' => 'apple', 'id' => 'section']])->groupsOf('calendar_oauth');
    }

    /**
     * @param list<array{section: string, group: string|null, id: string}> $testers
     */
    private function registry(array $testers): SectionTesterRegistry
    {
        return new SectionTesterRegistry(
            $testers,
            new ServiceLocator(['google' => static fn (): GoogleGroupTester => new GoogleGroupTester(), 'section' => static fn (): CalendarSectionTester => new CalendarSectionTester()]),
            new SettingsSectionRegistry([new CalendarOAuthFixtureSection(), new GeneralFixtureSection()]),
        );
    }
}
