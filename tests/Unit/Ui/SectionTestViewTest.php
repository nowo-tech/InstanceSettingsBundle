<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Ui;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\InstanceSettingsBundle\SectionTest\StoredTestReport;
use Nowo\InstanceSettingsBundle\SectionTest\TestCheck;
use Nowo\InstanceSettingsBundle\SectionTest\TestReport;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ReaderDateFormatter;
use Nowo\InstanceSettingsBundle\Ui\SectionTestButton;
use Nowo\InstanceSettingsBundle\Ui\SectionTestControls;
use Nowo\InstanceSettingsBundle\Ui\SectionTestView;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

use function date_default_timezone_get;

/**
 * 1.18: the translated view of a stored report (the page and the endpoint share it).
 */
final class SectionTestViewTest extends TestCase
{
    public function testWithoutATranslatorTheKeysAreShown(): void
    {
        $report = StoredTestReport::of('mailer', null, TestReport::of(TestCheck::ok('a.b', 'c.d', ['%x%' => true])), new DateTimeImmutable('2026-11-14 10:00:00 UTC'), null);

        self::assertSame([
            'section'     => 'mailer',
            'group'       => null,
            'status'      => 'ok',
            'statusLabel' => 'test.status.ok',
            'testedAt'    => '2026-11-14T10:00:00+00:00',
            'time'        => (new DateTimeImmutable('2026-11-14 10:00:00 UTC'))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i T'),
            'summary'     => 'test.summary_anonymous',
            'checks'      => [['status' => 'ok', 'statusLabel' => 'test.status.ok', 'label' => 'a.b', 'hint' => 'c.d']],
        ], SectionTestView::json($report, 'app', null));
    }

    public function testParametersAreScalarsForTheTranslator(): void
    {
        $report     = StoredTestReport::of('mailer', 'smtp', TestReport::of(TestCheck::fail('a.b', 'c.d', ['%on%' => true, '%off%' => false, '%n%' => 2])), new DateTimeImmutable('2026-11-14 10:00:00 UTC'), 'admin');
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['c.d' => 'on=%on% off=%off% n=%n%'], 'en', 'app');
        $view = SectionTestView::json($report, 'app', $translator);

        self::assertSame('on=1 off=0 n=2', $view['checks'][0]['hint']);
        self::assertSame('test.summary', $view['summary']);
    }

    public function testTheTimeGoesThroughTheDateFormatterInTheApplicationZone(): void
    {
        $report     = StoredTestReport::of('mailer', null, TestReport::of(TestCheck::ok('a.b')), new DateTimeImmutable('2026-11-27 16:14:05 UTC'), 'admin');
        $translator = new Translator('es');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['test.summary' => 'Última prueba: %status% (%time%, %actor%)'], 'es', 'NowoInstanceSettingsBundle');

        // The application's zone is the process default (never changed here: worker-safe tests).
        $local = $report->testedAt->setTimezone(new DateTimeZone(date_default_timezone_get()));

        self::assertSame($local->format('Y-m-d H:i T'), SectionTestView::json($report, 'app', null)['time'], 'UiKit\'s default keeps the 1.18 text');

        $view = SectionTestView::json($report, 'app', $translator, new ReaderDateFormatter());
        self::assertSame('reader ' . $local->format('d/m/Y H:i T'), $view['time']);
        self::assertSame('2026-11-27T16:14:05+00:00', $view['testedAt'], 'the instant stays ISO-8601 for <time datetime>');
        self::assertSame('Última prueba: test.status.ok (' . $view['time'] . ', admin)', $view['summary']);
    }

    public function testButtonsAndControls(): void
    {
        self::assertSame('section', (new SectionTestButton(null, null, null))->idSuffix());
        self::assertSame('group-colors-light', (new SectionTestButton('colors.light', null, null))->idSuffix());
        self::assertSame('nowo_instance_settings_section_mailer', (new SectionTestControls('mailer', '/x', 'app', []))->tokenId());
    }
}
