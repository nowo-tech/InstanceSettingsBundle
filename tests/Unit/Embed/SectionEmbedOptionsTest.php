<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Embed;

use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Embed\SectionEmbedOptions;
use Nowo\InstanceSettingsBundle\Ui\SectionLayout;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * IS-1 (1.6): embed options hold host route names only, an allowlist of valid section names, and the overrides.
 */
final class SectionEmbedOptionsTest extends TestCase
{
    public function testRoutesForm(): void
    {
        $options = SectionEmbedOptions::routes(['brand' => 'app_brand', 'appearance' => 'app.appearance-theme'], ['tenant' => 'acme']);

        self::assertSame(['brand', 'appearance'], $options->sections);
        self::assertTrue($options->allows('brand'));
        self::assertFalse($options->allows('mailer'));
        self::assertSame(['app.appearance-theme', ['tenant' => 'acme']], $options->routeOf('appearance'));
        self::assertNull($options->template);
        self::assertNull($options->layout);
        self::assertNull($options->header);
        self::assertSame([], $options->context);
    }

    public function testSingleRouteFormAndOverrides(): void
    {
        $header  = new PageHeader(new Label('Appearance'));
        $options = SectionEmbedOptions::route('app_appearance', ['brand', 'appearance', 'brand'], 'tab', ['x' => 1])
            ->withTemplate('host/appearance.html.twig')
            ->withLayout('host/layout.html.twig')
            ->withHeader($header)
            ->withContext(['menu' => 'appearance']);

        self::assertSame(['brand', 'appearance'], $options->sections, 'duplicates collapse');
        self::assertSame(['app_appearance', ['x' => 1, 'tab' => 'brand']], $options->routeOf('brand'));
        self::assertSame(['host/appearance.html.twig', 'host/layout.html.twig', $header, ['menu' => 'appearance']], [$options->template, $options->layout, $options->header, $options->context]);
        self::assertNull($options->withTemplate(null)->withLayout(null)->withHeader(null)->template);
    }

    public function testSectionLayoutOverride(): void
    {
        $options = SectionEmbedOptions::routes(['brand' => 'app_brand']);
        self::assertNull($options->sectionLayout);

        $tabs = $options->withSectionLayout('tabs')->withTemplate('t.html.twig')->withLayout('l.html.twig')->withHeader(null)->withContext(['a' => 1]);
        self::assertSame(SectionLayout::Tabs, $tabs->sectionLayout, 'kept by every other copy');
        self::assertSame(SectionLayout::Stacked, $tabs->withSectionLayout(SectionLayout::Stacked)->sectionLayout);
        self::assertNull($tabs->withSectionLayout(null)->sectionLayout);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid section layout "grid"');
        $options->withSectionLayout('grid');
    }

    public function testRouteOfAnUnlistedSectionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The section "mailer" is not embedded.');
        SectionEmbedOptions::routes(['brand' => 'app_brand'])->routeOf('mailer');
    }

    /**
     * @return iterable<string, array{0: callable(): SectionEmbedOptions, 1: string}>
     */
    public static function invalid(): iterable
    {
        yield 'no section' => [static fn (): SectionEmbedOptions => SectionEmbedOptions::routes([]), 'needs at least one section'];
        yield 'bad section name' => [static fn (): SectionEmbedOptions => SectionEmbedOptions::routes(['../x' => 'app_x']), 'Invalid embedded section name "../x"'];
        // @phpstan-ignore argument.type
        yield 'non-string section' => [static fn (): SectionEmbedOptions => SectionEmbedOptions::route('app_x', [12]), 'Invalid embedded section name "int"'];
        yield 'url as route' => [static fn (): SectionEmbedOptions => SectionEmbedOptions::routes(['brand' => 'https://evil.example/']), 'must be a route name, not a path or a URL'];
        yield 'path as route' => [static fn (): SectionEmbedOptions => SectionEmbedOptions::route('/developer/appearance', ['brand']), 'must be a route name, not a path or a URL'];
        yield 'bad parameter' => [static fn (): SectionEmbedOptions => SectionEmbedOptions::route('app_x', ['brand'], 'a-b'), 'Invalid section route parameter "a-b"'];
        yield 'empty template' => [static fn (): SectionEmbedOptions => SectionEmbedOptions::routes(['brand' => 'app_x'])->withTemplate(''), 'must not be empty strings'];
        yield 'empty layout' => [static fn (): SectionEmbedOptions => SectionEmbedOptions::routes(['brand' => 'app_x'])->withLayout(''), 'must not be empty strings'];
    }

    #[DataProvider('invalid')]
    public function testInvalidOptionsAreRefused(callable $build, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $build();
    }
}
