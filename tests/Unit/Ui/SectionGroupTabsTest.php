<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Ui;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Section\SettingsGroup;
use Nowo\InstanceSettingsBundle\Ui\SectionGroupTab;
use Nowo\InstanceSettingsBundle\Ui\SectionGroupTabs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormView;

/**
 * 1.14: the subtabs view (open tab, error counts, labels, links).
 */
final class SectionGroupTabsTest extends TestCase
{
    public function testTheFirstTabOpensByDefaultAndTheRequestedOneWhenKnown(): void
    {
        $groups = [new SettingsGroup('one', fields: ['a']), new SettingsGroup('two', 'own.label', ['b', 'missing'], translationDomain: 'other')];
        $tabs   = SectionGroupTabs::build('look', $groups, 'app', $this->form([]), null);

        self::assertSame('nowo-instance-settings-look-groups', $tabs->id);
        self::assertSame('one', $tabs->active);
        self::assertSame([true, false], array_map(static fn (SectionGroupTab $t): bool => $t->active, $tabs->tabs));
        self::assertSame('nowo-instance-settings-look-groups-two', $tabs->tabs[1]->tabId);
        self::assertSame('?settings_group=two', $tabs->tabs[1]->href);
        self::assertSame('settings_group', $tabs->parameter);
        self::assertSame(['look.group.one', 'app'], [$tabs->tabs[0]->label->key, $tabs->tabs[0]->label->domain]);
        self::assertSame(['own.label', 'other'], [$tabs->tabs[1]->label->key, $tabs->tabs[1]->label->domain]);
        self::assertSame(['b', 'missing'], $tabs->tabs[1]->fields);
        self::assertFalse($tabs->tabs[0]->isInvalid());
        self::assertTrue($tabs->has('two'));
        self::assertFalse($tabs->has('three'));
        self::assertFalse($tabs->has(null));

        self::assertSame('two', SectionGroupTabs::build('look', $groups, 'app', $this->form([]), 'two')->active);
        self::assertSame('one', SectionGroupTabs::build('look', $groups, 'app', $this->form([]), 'nope')->active);

        $custom = SectionGroupTabs::build('look', $groups, 'app', $this->form([]), null, 'tab');
        self::assertSame('tab', $custom->parameter);
        self::assertSame('?tab=two', $custom->tabs[1]->href);
    }

    public function testTheFirstTabWithAnErrorWinsAndCountsItsErrors(): void
    {
        $groups = [new SettingsGroup('one', fields: ['a']), new SettingsGroup('two', fields: ['b', 'c', 'd']), new SettingsGroup('three', fields: ['e'])];
        $tabs   = SectionGroupTabs::build('look', $groups, 'app', $this->form(['b' => false, 'c' => false, 'd' => true, 'e' => false]), 'one');

        self::assertSame('two', $tabs->active);
        self::assertSame([0, 2, 1], array_map(static fn (SectionGroupTab $t): int => $t->errors, $tabs->tabs));
        self::assertTrue($tabs->tabs[1]->isInvalid());
    }

    public function testAtLeastTwoTabs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SectionGroupTabs('id', 'a', []);
    }

    /**
     * @param array<string, bool> $valid child name => vars.valid
     */
    private function form(array $valid): FormView
    {
        $view = new FormView();
        foreach ($valid as $name => $isValid) {
            $child                 = new FormView($view);
            $child->vars['valid']  = $isValid;
            $view->children[$name] = $child;
        }

        return $view;
    }
}
