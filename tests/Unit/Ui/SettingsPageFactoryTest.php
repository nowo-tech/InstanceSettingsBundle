<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Ui;

use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\MailerFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\PageFixtures;
use Nowo\InstanceSettingsBundle\Ui\SettingsPages;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\SettingsSection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormView;

final class SettingsPageFactoryTest extends TestCase
{
    public function testSectionPage(): void
    {
        $form   = new FormView();
        $action = Action::view('/x', 'Test');
        $page   = PageFixtures::factory()->section(['mailer' => new MailerFixtureSection(), 'general' => new GeneralFixtureSection()], 'mailer', $form, [$action], true);

        self::assertSame('mailer', $page->current);
        self::assertTrue($page->hasTabs());
        self::assertSame('/nowo_instance_settings_section?section=general', $page->sections[1]->href);
        self::assertSame($form, $page->sections[0]->form);
        self::assertNull($page->sections[1]->form);
        self::assertSame([$action], $page->sections[0]->actions);
        self::assertSame([], $page->sections[1]->actions);
        self::assertInstanceOf(Label::class, $page->sections[0]->intro);
        self::assertSame('mailer.intro', $page->sections[0]->intro->key);
        self::assertNull($page->sections[1]->intro);
        self::assertSame('config', $page->sections[0]->icon);
        self::assertInstanceOf(Label::class, $page->sections[0]->label);
        self::assertSame('app', $page->sections[0]->label->domain);
        self::assertSame('export', $page->header->actions[0]->kind);
        self::assertSame('NowoInstanceSettingsBundle', $page->bundle);

        self::assertSame([], PageFixtures::factory()->section(['mailer' => new MailerFixtureSection()], 'mailer', $form)->header->actions);
    }

    public function testEmptyAndPortabilityPages(): void
    {
        $empty = PageFixtures::factory()->empty(true);
        self::assertSame('empty', $empty->sections[0]->id);
        self::assertCount(1, $empty->header->actions);
        self::assertSame([], PageFixtures::factory()->empty()->header->actions);

        $page = PageFixtures::factory(new SettingsPages('layouts/admin.html.twig', ['portability' => 'host/p.html.twig']))->portability(new FormView(), new FormView(), true);
        self::assertSame(['export', 'import'], array_map(static fn (SettingsSection $s): string => $s->id, $page->sections));
        self::assertInstanceOf(Label::class, $page->sections[0]->intro);
        self::assertSame('portability.export.intro_secrets', $page->sections[0]->intro->key);
        self::assertSame('layouts/admin.html.twig', $page->layout);
        $intro = PageFixtures::factory()->portability(new FormView(), new FormView(), false)->sections[0]->intro;
        self::assertInstanceOf(Label::class, $intro);
        self::assertSame('portability.export.intro', $intro->key);
    }

    public function testPagesPlumbing(): void
    {
        $pages = new SettingsPages('', ['section' => 'host/section.html.twig']);
        self::assertNull($pages->layout());
        self::assertSame('host/section.html.twig', $pages->template('section'));
        self::assertSame(SettingsPages::TEMPLATES['portability'], $pages->template('portability'));
        self::assertSame('unknown', $pages->template('unknown'));
        self::assertSame('host/section.html.twig', PageFixtures::factory($pages)->template('section'));
        self::assertSame(['%n%' => 1], SettingsPages::label('k', ['%n%' => 1])->params);
    }
}
