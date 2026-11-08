<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\UiKitBundle\Appearance\Appearance;
use Nowo\UiKitBundle\Appearance\AppearancePresetRegistry;
use Nowo\UiKitBundle\Appearance\AppearanceProviderInterface;
use Nowo\UiKitBundle\Appearance\ConfigAppearanceProvider;

/**
 * A host appearance provider (`nowo_ui_kit.appearance.provider: app.appearance`).
 */
final class HostAppearanceProvider implements AppearanceProviderInterface
{
    public function getAppearance(): Appearance
    {
        return (new ConfigAppearanceProvider(new AppearancePresetRegistry(), ['brand_name' => 'Host']))->getAppearance();
    }
}
