<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Routing;

/**
 * Public route names of the admin UI (stable API): link to a section page from a kit or the host with
 * `path(InstanceSettingsRoutes::SECTION, {section: 'mailer'})`.
 */
final class InstanceSettingsRoutes
{
    /** Settings index (redirects to the first section the user may open). */
    public const INDEX = 'nowo_instance_settings_index';

    /** Section page; parameter `section`. */
    public const SECTION = 'nowo_instance_settings_section';

    /** Import / export page. */
    public const PORTABILITY = 'nowo_instance_settings_portability';

    /** Export (POST + CSRF). */
    public const EXPORT = 'nowo_instance_settings_export';

    /** Import (POST + CSRF). */
    public const IMPORT = 'nowo_instance_settings_import';
}
