<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\InstanceSettingsBundle\DependencyInjection\Compiler\SettingsTesterPass;
use Nowo\InstanceSettingsBundle\NowoInstanceSettingsBundle;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRouteLoader;
use Nowo\InstanceSettingsBundle\SectionTest\SectionTesterRegistry;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\CalendarSectionTester;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\SectionTest\GoogleGroupTester;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * 1.18: the tester tags become the tester registry's declarations and service locator.
 */
final class SettingsTesterPassTest extends TestCase
{
    public function testCollectsTheTesters(): void
    {
        $container = $this->container();
        $container->register('app.section', CalendarSectionTester::class)->addTag(NowoInstanceSettingsBundle::TESTER_TAG, ['section' => 'calendar_oauth', 'group' => null]);
        $container->register('app.google', GoogleGroupTester::class)->addTag(NowoInstanceSettingsBundle::TESTER_TAG, ['section' => 'calendar_oauth', 'group' => 'google']);
        $container->register('app.class_less')->addTag(NowoInstanceSettingsBundle::TESTER_TAG, ['section' => 'mailer']);

        (new SettingsTesterPass())->process($container);

        $definition = $container->getDefinition(SectionTesterRegistry::class);
        self::assertSame([
            ['section' => 'calendar_oauth', 'group' => null, 'id' => 'app.section'],
            ['section' => 'calendar_oauth', 'group' => 'google', 'id' => 'app.google'],
            ['section' => 'mailer', 'group' => null, 'id' => 'app.class_less'],
        ], $definition->getArgument(0));
        self::assertInstanceOf(Reference::class, $definition->getArgument(1));
        self::assertTrue($container->getParameter(InstanceSettingsRouteLoader::SECTION_TESTS_PARAMETER));
    }

    public function testWithoutTesters(): void
    {
        $container = $this->container();
        (new SettingsTesterPass())->process($container);

        self::assertSame([], $container->getDefinition(SectionTesterRegistry::class)->getArgument(0));
        self::assertNull($container->getDefinition(SectionTesterRegistry::class)->getArgument(1));
        self::assertFalse($container->getParameter(InstanceSettingsRouteLoader::SECTION_TESTS_PARAMETER));

        // The admin UI off: no registry, only the parameter.
        $bare = new ContainerBuilder();
        $bare->register('app.section', CalendarSectionTester::class)->addTag(NowoInstanceSettingsBundle::TESTER_TAG, ['section' => 'calendar_oauth']);
        (new SettingsTesterPass())->process($bare);
        self::assertTrue($bare->getParameter(InstanceSettingsRouteLoader::SECTION_TESTS_PARAMETER));
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>, 2: string}>
     */
    public static function invalid(): iterable
    {
        yield 'no section' => [CalendarSectionTester::class, [], 'needs a valid "section"'];
        yield 'bad section' => [CalendarSectionTester::class, ['section' => 'Calendar'], 'needs a valid "section"'];
        yield 'bad group' => [CalendarSectionTester::class, ['section' => 'calendar_oauth', 'group' => 'Google'], 'invalid "group"'];
        yield 'group not a string' => [CalendarSectionTester::class, ['section' => 'calendar_oauth', 'group' => 3], 'invalid "group"'];
        yield 'not a tester' => [stdClass::class, ['section' => 'calendar_oauth'], 'must implement'];
    }

    /**
     * @param array<string, mixed> $tag
     */
    #[DataProvider('invalid')]
    public function testInvalidTags(string $class, array $tag, string $message): void
    {
        $container = $this->container();
        $container->register('app.tester', $class)->addTag(NowoInstanceSettingsBundle::TESTER_TAG, $tag);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage($message);
        (new SettingsTesterPass())->process($container);
    }

    public function testTwoTestersOfTheSameSectionAndGroup(): void
    {
        $container = $this->container();
        $container->register('app.one', GoogleGroupTester::class)->addTag(NowoInstanceSettingsBundle::TESTER_TAG, ['section' => 'calendar_oauth', 'group' => 'google']);
        $container->register('app.two', GoogleGroupTester::class)->addTag(NowoInstanceSettingsBundle::TESTER_TAG, ['section' => 'calendar_oauth', 'group' => 'google']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two section testers for the settings section "calendar_oauth" group "google": "app.one" and "app.two"');
        (new SettingsTesterPass())->process($container);
    }

    public function testTwoSectionTesters(): void
    {
        $container = $this->container();
        $container->register('app.one', CalendarSectionTester::class)->addTag(NowoInstanceSettingsBundle::TESTER_TAG, ['section' => 'calendar_oauth']);
        $container->register('app.two', CalendarSectionTester::class)->addTag(NowoInstanceSettingsBundle::TESTER_TAG, ['section' => 'calendar_oauth']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Two section testers for the settings section "calendar_oauth": "app.one" and "app.two"');
        (new SettingsTesterPass())->process($container);
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition(SectionTesterRegistry::class, new Definition(SectionTesterRegistry::class, [[], null, null]));

        return $container;
    }
}
