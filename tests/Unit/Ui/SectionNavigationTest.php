<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Ui;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;
use Nowo\InstanceSettingsBundle\Ui\SectionNavigation;
use PHPUnit\Framework\TestCase;

use function preg_match;

/**
 * 1.16: `web_ui.section_icons` and `web_ui.navigation_hidden` (presentation only).
 */
final class SectionNavigationTest extends TestCase
{
    public function testDefaultsChangeNothing(): void
    {
        $navigation = new SectionNavigation();
        $mailer     = new MailerFixtureSection();

        self::assertSame('config', $navigation->icon('mailer', $mailer));
        self::assertNull($navigation->icon('general', new GeneralFixtureSection()));
        self::assertFalse($navigation->isHidden('mailer'));
        self::assertSame('mailer', $navigation->firstSection(['mailer' => $mailer, 'general' => new GeneralFixtureSection()]));
        self::assertNull($navigation->firstSection([]));
        $navigation->reportUnknown(['mailer']);
    }

    public function testIconOverridesAndFills(): void
    {
        $navigation = new SectionNavigation(['mailer' => 'tabler:mail', 'general' => 'config']);

        self::assertSame('tabler:mail', $navigation->icon('mailer', new MailerFixtureSection()), 'overrides the section icon');
        self::assertSame('config', $navigation->icon('general', new GeneralFixtureSection()), 'fills a section without icon');
    }

    public function testFirstSectionSkipsHiddenOnesAndFallsBackWhenAllAreHidden(): void
    {
        $sections = ['mailer' => new MailerFixtureSection(), 'general' => new GeneralFixtureSection()];

        self::assertTrue((new SectionNavigation(hidden: ['mailer']))->isHidden('mailer'));
        self::assertSame('general', (new SectionNavigation(hidden: ['mailer']))->firstSection($sections));
        self::assertSame('mailer', (new SectionNavigation(hidden: ['mailer', 'general', 'mailer']))->firstSection($sections), 'all hidden: the behaviour before 1.16');
    }

    public function testUnknownNamesAreIgnoredWithADebugLine(): void
    {
        $logger     = new RecordingLogger();
        $navigation = new SectionNavigation(['mailer' => 'config', 'nope' => 'config'], ['general', 'gone'], $logger);
        $navigation->reportUnknown(['mailer', 'general']);

        self::assertCount(2, $logger->records);
        self::assertSame('debug', $logger->records[0]['level']);
        self::assertSame(['option' => 'nowo_instance_settings.web_ui.section_icons', 'sections' => 'nope'], $logger->records[0]['context']);
        self::assertSame(['option' => 'nowo_instance_settings.web_ui.navigation_hidden', 'sections' => 'gone'], $logger->records[1]['context']);

        $quiet = new RecordingLogger();
        (new SectionNavigation(['mailer' => 'config'], ['general'], $quiet))->reportUnknown(['mailer', 'general']);
        self::assertSame([], $quiet->records);
    }

    public function testIconPattern(): void
    {
        foreach (['config', 'filter_off', 'tabler:palette', 'tabler:layout-dashboard', 'bi:chat-dots', 'mdi:numeric-1'] as $icon) {
            self::assertSame(1, preg_match(SectionNavigation::ICON_PATTERN, $icon), $icon);
        }
        foreach (['', 'Tabler:palette', 'ti ti-x', 'a:b:c', ':x', 'x:', 'x:_y', "config\n", '<b>'] as $icon) {
            self::assertSame(0, preg_match(SectionNavigation::ICON_PATTERN, $icon), $icon);
        }
    }
}
