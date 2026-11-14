<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Ui;

use Nowo\InstanceSettingsBundle\Embed\SettingsSectionEmbedder;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionInterface;
use Psr\Log\LoggerInterface;

use function array_diff;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_unique;
use function array_values;
use function implode;
use function in_array;

/**
 * Presentation of the sections in the bundle's settings page (1.16): `web_ui.section_icons` (section name => icon,
 * overriding or filling {@see SettingsSectionInterface::getIcon()}) and `web_ui.navigation_hidden` (sections left out
 * of the tab bar and of the "first section" redirect).
 *
 * Presentation only: a hidden section stays registered, guarded by its own area and embeddable through
 * {@see SettingsSectionEmbedder}; nothing here grants or denies access. Section
 * names are only known at runtime (other bundles contribute sections), so a configured name that matches no registered
 * section is ignored and reported with a debug log line ({@see self::reportUnknown()}), never an error: a host keeps
 * booting when a bundle that contributed the section is removed or switched off (e.g. `brand.enabled: false`).
 *
 * Configuration only, no request data and no memo: safe in FrankenPHP worker mode with the kernel reset off.
 */
final readonly class SectionNavigation
{
    /**
     * Accepted icon strings: a canonical UiKit kind (`config`, `filter_off`) or a prefixed icon name (`tabler:palette`,
     * `bi:gear`). Lower case letters and digits, `-` / `_` separators, at most one `prefix:` part.
     */
    public const ICON_PATTERN = '/^[a-z0-9]+(?:[-_][a-z0-9]+)*(?::[a-z0-9]+(?:-[a-z0-9]+)*)?$/D';

    /** @var list<string> */
    private array $hidden;

    /**
     * @param array<string, string> $icons `web_ui.section_icons` (section name => icon)
     * @param iterable<string> $hidden `web_ui.navigation_hidden` (section names)
     * @param LoggerInterface|null $logger debug line for configured names that match no registered section
     */
    public function __construct(
        private array $icons = [],
        iterable $hidden = [],
        private ?LoggerInterface $logger = null,
    ) {
        $names = [];
        foreach ($hidden as $name) {
            $names[] = $name;
        }
        $this->hidden = array_values(array_unique($names));
    }

    /**
     * Icon of a section tab: the `web_ui.section_icons` entry, else the section's own {@see SettingsSectionInterface::getIcon()}.
     */
    public function icon(string $name, SettingsSectionInterface $section): ?string
    {
        return array_key_exists($name, $this->icons) ? $this->icons[$name] : $section->getIcon();
    }

    /**
     * Whether a section is left out of the bundle page's tab bar and of the "first section" redirect.
     */
    public function isHidden(string $name): bool
    {
        return in_array($name, $this->hidden, true);
    }

    /**
     * The first section of `$sections` (in order) that is not hidden; when every one is hidden, the first one (the
     * behaviour before 1.16); null when `$sections` is empty.
     *
     * @param array<string, SettingsSectionInterface> $sections sections the user may open, in order
     */
    public function firstSection(array $sections): ?string
    {
        foreach (array_keys($sections) as $name) {
            if (!$this->isHidden($name)) {
                return $name;
            }
        }

        return array_key_first($sections);
    }

    /**
     * Logs (debug) the configured section names that match no registered section: they are ignored.
     *
     * @param list<string> $registered names of the registered sections
     */
    public function reportUnknown(array $registered): void
    {
        if (!$this->logger instanceof LoggerInterface) {
            return;
        }
        foreach (['web_ui.section_icons' => array_keys($this->icons), 'web_ui.navigation_hidden' => $this->hidden] as $option => $names) {
            $unknown = array_values(array_diff($names, $registered));
            if ($unknown !== []) {
                $this->logger->debug('Instance settings: {option} names unknown sections ({sections}); they are ignored.', [
                    'option'   => 'nowo_instance_settings.' . $option,
                    'sections' => implode(', ', $unknown),
                ]);
            }
        }
    }
}
