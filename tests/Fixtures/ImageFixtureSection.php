<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Section\AbstractSettingsSection;
use Nowo\InstanceSettingsBundle\Section\SettingField;

/**
 * Section with image fields: a public logo with icon variants, a public square icon, a private scan (JPEG only) and a
 * non-portable image, plus a plain string.
 */
final class ImageFixtureSection extends AbstractSettingsSection
{
    public function getName(): string
    {
        return 'brand';
    }

    public function getTranslationDomain(): string
    {
        return 'app';
    }

    public function getFields(): iterable
    {
        yield SettingField::string('name', 'Acme');
        yield SettingField::image('logo', maxWidth: 2400, maxHeight: 800, variants: [32, 192], public: true);
        yield SettingField::image('icon', minWidth: 32, minHeight: 32, square: true, public: true);
        yield SettingField::image('scan', types: ['image/jpeg'], maxBytes: 200_000);
        yield SettingField::image('local_only')->notPortable();
    }
}
