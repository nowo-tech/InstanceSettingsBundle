<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Fixtures;

use Nowo\InstanceSettingsBundle\Embed\SectionEmbedOptions;
use Nowo\InstanceSettingsBundle\Embed\SettingsSectionEmbedder;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A host page embedding settings sections (1.6, IS-1): `/host/settings/{section}` (one route with a section parameter,
 * allowlist general + brand), `/host/mailer` (secrets), `/host/look` (theme cards) and `/host/general` (one route per section, the host template,
 * layout and header).
 */
final readonly class EmbedHostController
{
    public function __construct(
        private SettingsSectionEmbedder $embedder,
    ) {
    }

    public function page(Request $request, string $section): Response
    {
        return $this->embedder->handle($request, $section, SectionEmbedOptions::route('host_settings', ['general', 'brand'], routeParameters: ['tenant' => 'acme']));
    }

    public function mailer(Request $request): Response
    {
        return $this->embedder->handle($request, 'mailer', SectionEmbedOptions::routes(['mailer' => 'host_mailer']));
    }

    public function look(Request $request): Response
    {
        return $this->embedder->handle($request, 'look', SectionEmbedOptions::routes(['look' => 'host_look']));
    }

    public function general(Request $request): Response
    {
        $options = SectionEmbedOptions::routes(['general' => 'host_general'])
            ->withTemplate('@Host/embed.html.twig')
            ->withLayout('@Host/layout.html.twig')
            ->withHeader(new PageHeader(new Label('Host appearance')))
            ->withContext(['host_note' => 'from the host', 'page' => 'never wins']);

        return $this->embedder->handle($request, 'general', $options);
    }
}
