<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\File\ImageUpload;
use Nowo\InstanceSettingsBundle\Routing\InstanceSettingsRoutes;
use Nowo\InstanceSettingsBundle\Tests\Fixtures\ImageFixtureSection;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\InstanceSettingsTestKernel;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\ImageFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\RouterInterface;

use function file_put_contents;
use function json_decode;
use function substr;
use function tempnam;

use const JSON_THROW_ON_ERROR;

/**
 * Image fields end to end (spec 220 S2): upload through the CSRF section form with the section role, refused uploads,
 * removal, the anonymous file route (public only, same 404 otherwise, ETag / 304), export with files, purge command,
 * and the defaults for hosts without image fields.
 */
final class ImageFieldsTest extends KernelTestCase
{
    public function testHostsWithoutImageFieldsGetNoFileRoute(): void
    {
        // A configuration of its own: kernels with the same container share its compiled class in one process.
        $kernel = $this->bootKernel(['files' => ['orphan_retention' => 3]]);
        $router = $this->container($kernel)->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);
        self::assertNull($router->getRouteCollection()->get(InstanceSettingsRoutes::FILE));

        $client = $this->client($kernel, null);
        $client->request('GET', '/_settings-file/general/site_name/0123456789ab.png');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        // The export envelope stays version 1.
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/admin/settings/portability');
        self::assertCount(0, $crawler->filter('input[name="portability_export[include_files]"]'));
        $admin->submit($crawler->filter('form[name="portability_export"]')->form());
        self::assertSame(1, json_decode((string) $admin->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['version']);
    }

    public function testUploadServeReplaceAndRemove(): void
    {
        $kernel = $this->imageKernel();
        $admin  = $this->client($kernel);

        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        $form = $crawler->filter('form[name="settings_section"]')->form();
        self::assertSame('multipart/form-data', $crawler->filter('form[name="settings_section"]')->attr('enctype'));
        self::assertCount(0, $crawler->filter('input[name="settings_section[logo][remove]"]'));

        self::upload($form, 'settings_section[logo][file]', $this->tempFile(ImageFactory::jpegWithExif(1, 120, 40) . '<script>alert(1)</script>'));
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());

        $file = $this->settings($kernel)->getFile('brand.logo');
        self::assertNotNull($file);
        self::assertSame('image/jpeg', $file->mimeType);
        $url = (string) $file->url();
        self::assertSame('/_settings-file/brand/logo/' . substr($file->sha256, 0, 12) . '.jpg', $url);

        // Anonymous visitors get the public image with the hardened headers.
        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', $url);
        $response = $anonymous->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/jpeg', $response->headers->get('Content-Type'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'));
        self::assertStringStartsWith('inline;', (string) $response->headers->get('Content-Disposition'));
        $body = (string) $response->getContent();
        self::assertStringNotContainsString('<script>', $body);
        self::assertStringNotContainsString('SECRET-EXIF-PAYLOAD', $body);

        $anonymous->request('GET', $url, server: ['HTTP_IF_NONE_MATCH' => '"' . $file->sha256 . '"']);
        self::assertSame(304, $anonymous->getResponse()->getStatusCode());
        $anonymous->request('GET', (string) $file->url(192));
        self::assertSame(200, $anonymous->getResponse()->getStatusCode());
        self::assertSame('image/png', $anonymous->getResponse()->headers->get('Content-Type'));
        $anonymous->request('GET', substr($url, 0, -4) . '.png');
        self::assertSame(404, $anonymous->getResponse()->getStatusCode(), 'wrong extension');
        $anonymous->request('POST', $url);
        self::assertSame(405, $anonymous->getResponse()->getStatusCode());

        // Replace: the old URL keeps answering during the retention; remove.
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[logo][file]', $this->tempFile(ImageFactory::png(100, 50)));
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        self::assertSame('image/png', $this->settings($kernel)->getFile('brand.logo')?->mimeType);
        $anonymous->request('GET', $url);
        self::assertSame(200, $anonymous->getResponse()->getStatusCode(), 'old hash served while its blob exists');

        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $form    = $crawler->filter('form[name="settings_section"]')->form();
        self::tick($form, 'settings_section[logo][remove]');
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        $this->settings($kernel)->reset();
        self::assertNull($this->settings($kernel)->getFile('brand.logo'));

        // The purge command deletes nothing before the retention, everything with retention 0.
        $application = new Application($kernel);
        $tester      = new CommandTester($application->find('nowo:instance-settings:purge-files'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('deleted 0', $tester->getDisplay());
        $anonymous->request('GET', $url);
        self::assertSame(200, $anonymous->getResponse()->getStatusCode());
    }

    public function testRefusedUploadsCsrfAndRoles(): void
    {
        $kernel  = $this->imageKernel();
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/admin/settings/sections/brand');
        $form    = $crawler->filter('form[name="settings_section"]')->form();

        // An SVG renamed .png with an image/png type.
        self::upload($form, 'settings_section[logo][file]', $this->tempFile('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'));
        $admin->submit($form);
        self::assertSame(422, $admin->getResponse()->getStatusCode());
        self::assertStringContainsString('The file is not a valid PNG, JPEG or WebP image.', (string) $admin->getResponse()->getContent());
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_file'));

        // Missing CSRF token: nothing is stored.
        $admin->request('POST', '/admin/settings/sections/brand', ['settings_section' => ['name' => 'X']], ['settings_section' => ['logo' => ['file' => new UploadedFile($this->tempFile(ImageFactory::png()), 'logo.png', 'image/png', null, true)]]]);
        self::assertSame(422, $admin->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_file'));

        // A user without the section role cannot upload (nor read a private image).
        $viewer = $this->client($kernel, 'viewer');
        $viewer->request('POST', '/admin/settings/sections/brand', [], ['settings_section' => ['logo' => ['file' => new UploadedFile($this->tempFile(ImageFactory::png()), 'logo.png', 'image/png', null, true)]]]);
        self::assertSame(403, $viewer->getResponse()->getStatusCode());
        self::assertSame(0, (int) $this->connection($kernel)->fetchOne('SELECT COUNT(*) FROM instance_setting_file'));

        // Path traversal in the client name is irrelevant: the name is never used.
        $form = $admin->request('GET', '/admin/settings/sections/brand')->filter('form[name="settings_section"]')->form();
        self::upload($form, 'settings_section[scan][file]', $this->tempFile(ImageFactory::jpeg()));
        $admin->submit($form);
        self::assertSame(302, $admin->getResponse()->getStatusCode());
        $scan = $this->settings($kernel)->getFile('brand.scan');
        self::assertNotNull($scan);
        self::assertFalse($scan->public);

        $anonymous = $this->client($kernel, null);
        $anonymous->request('GET', (string) $scan->url());
        self::assertSame(404, $anonymous->getResponse()->getStatusCode(), 'private image: same 404 as an unknown hash');
        $viewer->request('GET', (string) $scan->url());
        self::assertSame(404, $viewer->getResponse()->getStatusCode());
        $admin->request('GET', (string) $scan->url());
        self::assertSame(200, $admin->getResponse()->getStatusCode());
        self::assertTrue($admin->getResponse()->headers->hasCacheControlDirective('private'));
    }

    public function testExportWithFilesFromTheAdminPage(): void
    {
        $kernel = $this->imageKernel();
        $this->settings($kernel)->save('brand', ['logo' => ImageUpload::fromBytes(ImageFactory::png())]);
        $admin   = $this->client($kernel);
        $crawler = $admin->request('GET', '/admin/settings/portability');
        self::assertCount(1, $crawler->filter('input[name="portability_export[include_files]"][checked]'));
        $admin->submit($crawler->filter('form[name="portability_export"]')->form());

        $payload = json_decode((string) $admin->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $payload['version']);
        self::assertSame(['brand.logo'], array_keys($payload['files']));
    }

    private function imageKernel(): InstanceSettingsTestKernel
    {
        return $this->bootKernel(services: [ImageFixtureSection::class => ImageFixtureSection::class]);
    }

    private function tempFile(string $bytes): string
    {
        $path = (string) tempnam(InstanceSettingsTestKernel::baseDir(), 'up');
        file_put_contents($path, $bytes);

        return $path;
    }
}
