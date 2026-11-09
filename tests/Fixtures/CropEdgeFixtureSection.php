<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * Image fields with valid rules that the derived crop cannot fit (audit JP-M1): a banner whose minimum width is over
 * its maximum height, a 10 × 10 pixel, and a plain logo next to them.
 */
final class CropEdgeFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'edges';
    }

    public function getFields(): iterable
    {
        yield SettingField::image('banner', maxWidth: 1200, maxHeight: 400, minWidth: 600);
        yield SettingField::image('pixel', maxWidth: 10, maxHeight: 10);
        yield SettingField::image('logo', maxWidth: 2400, maxHeight: 800);
    }
}
