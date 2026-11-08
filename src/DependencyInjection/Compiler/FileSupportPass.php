<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\DependencyInjection\Compiler;

use Nowo\InstanceSettingsBundle\File\GdSupport;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fails closed at container compile time when `files.enabled: true` and GD lacks PNG, JPEG or WebP support (spec 220
 * §5.2): an image is never stored without GD's sniffing and re-encoding. The section registry checks again when the
 * definitions load, for a container compiled on another machine than the one that runs it.
 */
final class FileSupportPass implements CompilerPassInterface
{
    /** Container parameter set by the extension. */
    public const PARAMETER = 'nowo_instance_settings.files.enabled';

    /**
     * @param array<string, mixed>|null $gdInfo gd_info() to check against (null = the running PHP; tests)
     */
    public function __construct(
        private readonly ?array $gdInfo = null,
    ) {
    }

    /**
     * Throws a LogicException when the files are enabled and GD is incomplete.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasParameter(self::PARAMETER) || $container->getParameter(self::PARAMETER) !== true) {
            return;
        }

        GdSupport::assertAvailable($this->gdInfo);
    }
}
