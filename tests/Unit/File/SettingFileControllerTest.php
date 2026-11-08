<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\File;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\File\ImageProcessor;
use Nowo\InstanceSettingsBundle\File\SettingFileController;
use Nowo\InstanceSettingsBundle\Section\ImageSpec;
use Nowo\InstanceSettingsBundle\Section\SettingsSectionRegistry;
use Nowo\InstanceSettingsBundle\Security\AccessGuard;
use Nowo\InstanceSettingsBundle\Security\InstanceSettingsAccessCheckerInterface;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\GeneralFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingFileStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function strlen;
use function substr;

/**
 * The file route: headers of spec 220 §5.6, ETag / 304, HEAD, the same 404 for every miss, area check of non-public
 * fields, field binding and old hashes.
 */
final class SettingFileControllerTest extends TestCase
{
    private InMemorySettingFileStore $store;

    private string $logo;

    private string $scan;

    protected function setUp(): void
    {
        $this->store = new InMemorySettingFileStore();
        $processor   = new ImageProcessor();
        $logo        = $processor->process(new ImageSpec(variants: [32, 192]), ImageFactory::png(64, 32));
        $scan        = $processor->process(new ImageSpec(), ImageFactory::jpeg());
        $this->store->save('brand', 'logo', $logo, new DateTimeImmutable());
        $this->store->save('brand', 'scan', $scan, new DateTimeImmutable());
        $this->logo = $logo->sha256();
        $this->scan = $scan->sha256();
    }

    public function testPublicFileHeaders(): void
    {
        $response = $this->get(true, 'brand', 'logo', substr($this->logo, 0, 12), 'png');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('inline; filename=brand-logo.png', $response->headers->get('Content-Disposition'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        self::assertSame('cross-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertSame('"' . $this->logo . '"', $response->getEtag());
        self::assertTrue($response->headers->hasCacheControlDirective('public'));
        self::assertTrue($response->headers->hasCacheControlDirective('immutable'));
        self::assertSame('31536000', $response->headers->getCacheControlDirective('max-age'));
        self::assertSame(ImageProcessor::sniff((string) $response->getContent()), 'image/png');
        self::assertSame((string) strlen((string) $response->getContent()), $response->headers->get('Content-Length'));
    }

    public function testVariantsAndEtags(): void
    {
        $response = $this->get(true, 'brand', 'logo', substr($this->logo, 0, 12) . '-192', 'png');
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('"' . $this->logo . '-192"', $response->getEtag());

        // A variant the field does not declare.
        self::assertSame(404, $this->get(true, 'brand', 'logo', substr($this->logo, 0, 12) . '-48', 'png')->getStatusCode());
    }

    public function testIfNoneMatchAnswers304WithoutReadingTheBytes(): void
    {
        $request = Request::create('/x');
        $request->headers->set('If-None-Match', '"' . $this->logo . '"');
        $response = $this->controller(true)($request, 'brand', 'logo', substr($this->logo, 0, 12), 'png');

        self::assertSame(304, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertSame(0, $this->store->reads);

        $request->headers->set('If-None-Match', '"something-else"');
        self::assertSame(200, $this->controller(true)($request, 'brand', 'logo', substr($this->logo, 0, 12), 'png')->getStatusCode());
        self::assertSame(1, $this->store->reads);
    }

    public function testHeadDoesNotReadTheBytes(): void
    {
        $response = $this->controller(true)(Request::create('/x', 'HEAD'), 'brand', 'logo', substr($this->logo, 0, 12), 'png');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('', (string) $response->getContent());
        self::assertNotSame('0', $response->headers->get('Content-Length'));
        self::assertSame(0, $this->store->reads);
    }

    public function testSame404ForEveryMiss(): void
    {
        $hash  = substr($this->logo, 0, 12);
        $cases = [
            'unknown section'          => ['nope', 'logo', $hash, 'png'],
            'not an image field'       => ['general', 'site_name', $hash, 'png'],
            'unknown field'            => ['brand', 'nope', $hash, 'png'],
            'unknown hash'             => ['brand', 'logo', '000000000000', 'png'],
            'wrong extension'          => ['brand', 'logo', $hash, 'jpg'],
            'another field\'s image'   => ['brand', 'icon', $hash, 'png'],
            'traversal in the name'    => ['brand', 'logo', '../../../etc/passwd', 'png'],
            'uppercase hash'           => ['brand', 'logo', 'ABCDEF012345', 'png'],
            'private field, anonymous' => ['brand', 'scan', substr($this->scan, 0, 12), 'jpg'],
        ];
        $bodies = [];
        foreach ($cases as $name => [$section, $field, $file, $ext]) {
            $response = $this->get(false, $section, $field, $file, $ext);
            self::assertSame(404, $response->getStatusCode(), $name);
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            $bodies[] = (string) $response->getContent();
        }
        self::assertSame([''], array_values(array_unique($bodies)), 'no difference between unknown and forbidden');
        self::assertSame(0, $this->store->reads);
    }

    public function testPrivateFieldNeedsTheSectionArea(): void
    {
        $response = $this->get(true, 'brand', 'scan', substr($this->scan, 0, 12), 'jpg');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
        self::assertSame('3600', $response->headers->getCacheControlDirective('max-age'));
        self::assertFalse($response->headers->hasCacheControlDirective('immutable'));
    }

    public function testAnOrphanedImageIsServedUntilPurged(): void
    {
        $this->store->orphan('brand', 'logo', $this->logo, new DateTimeImmutable('-10 days'));
        self::assertSame(200, $this->get(false, 'brand', 'logo', substr($this->logo, 0, 12), 'png')->getStatusCode());

        $this->store->purge(new DateTimeImmutable(), static fn (): array => []);
        self::assertSame(404, $this->get(false, 'brand', 'logo', substr($this->logo, 0, 12), 'png')->getStatusCode());
    }

    private function get(bool $granted, string $section, string $field, string $file, string $ext): Response
    {
        return $this->controller($granted)(Request::create('/x'), $section, $field, $file, $ext);
    }

    private function controller(bool $granted): SettingFileController
    {
        $checker = new class($granted) implements InstanceSettingsAccessCheckerInterface {
            public function __construct(private readonly bool $granted)
            {
            }

            public function canAccess(?object $user, ?string $area = null): bool
            {
                return $this->granted;
            }
        };

        return new SettingFileController(new SettingsSectionRegistry([new ImageFixtureSection(), new GeneralFixtureSection()]), $this->store, new AccessGuard($checker));
    }
}
