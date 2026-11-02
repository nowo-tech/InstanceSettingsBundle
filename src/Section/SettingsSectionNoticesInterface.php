<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Section;

use Nowo\UiKitBundle\View\Label;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Optional interface of a {@see SettingsSectionInterface}: warnings shown on top of the section form (e.g. "the
 * selected provider is missing its credentials"). The admin UI renders them as UiKit `warning` alerts.
 *
 * Notices must never contain a stored value (they are rendered in the page): name fields, not their content.
 */
interface SettingsSectionNoticesInterface
{
    /**
     * Notices of the section page being rendered: translation keys of the section domain, UiKit {@see Label}s or
     * Symfony {@see TranslatableMessage}s (null domain = the section domain).
     *
     * @param SectionActionContext $context section, request and user of the page being rendered
     *
     * @return iterable<Label|string|TranslatableMessage>
     */
    public function getNotices(SectionActionContext $context): iterable;
}
