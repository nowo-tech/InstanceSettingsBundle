<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Portability;

use Nowo\InstanceSettingsBundle\Portability\JsonUploadReader;
use Nowo\InstanceSettingsBundle\Portability\PortabilityException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use const UPLOAD_ERR_PARTIAL;

final class JsonUploadReaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/nowo-instance-settings-reader-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testReadsObjects(): void
    {
        $reader = new JsonUploadReader(2048);
        self::assertSame(2048, $reader->maxBytes());
        self::assertSame(['a' => 1], $reader->fromString('{"a":1}'));
        self::assertSame([], $reader->fromString('{}'));
        self::assertSame(['b' => true], $reader->fromPath($this->file('{"b":true}')));
        self::assertSame(['c' => 'x'], $reader->fromUploadedFile(new UploadedFile($this->file('{"c":"x"}'), 'c.json', 'application/json', null, true)));
    }

    public function testRefusals(): void
    {
        $reader = new JsonUploadReader(1024);
        $cases  = [
            'missing_file' => [
                static fn (): array => $reader->fromUploadedFile(null),
                static fn (): array => $reader->fromUploadedFile(new UploadedFile(__FILE__, 'x.json', 'application/json', UPLOAD_ERR_PARTIAL, true)),
                fn (): array => $reader->fromPath($this->dir . '/missing.json'),
                fn (): array => $reader->fromPath($this->dir),
            ],
            'too_large'     => [fn (): array => $reader->fromPath($this->file('{"a":"' . str_repeat('x', 2000) . '"}'))],
            'invalid_json'  => [static fn (): array => $reader->fromString('{nope')],
            'not_an_object' => [static fn (): array => $reader->fromString('[1,2]'), static fn (): array => $reader->fromString('"x"')],
        ];
        foreach ($cases as $reason => $calls) {
            foreach ($calls as $call) {
                try {
                    $call();
                    self::fail('Expected ' . $reason);
                } catch (PortabilityException $e) {
                    self::assertSame($reason, $e->getMessage());
                }
            }
        }
    }

    private function file(string $content): string
    {
        $path = $this->dir . '/' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($path, $content);

        return $path;
    }
}
