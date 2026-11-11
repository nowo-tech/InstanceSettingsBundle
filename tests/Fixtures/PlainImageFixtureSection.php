<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * Section whose only image field opts out of UiKit's image input box (`image_input: false`, 1.13 audit LM29-I3).
 */
final class PlainImageFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'plain_images';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('title', 'Acme');
        yield SettingField::image('banner', label: 'Banner', help: 'Shown on the home page.', public: true)->withFormOptions(['image_input' => false]);
    }
}
