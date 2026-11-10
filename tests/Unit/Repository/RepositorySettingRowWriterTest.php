<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\InstanceSettingsBundle\Repository\RepositorySettingRowWriter;
use Nowo\InstanceSettingsBundle\Repository\StoredSettingRow;
use Nowo\InstanceSettingsBundle\Tests\Support\InMemorySettingValueRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RepositorySettingRowWriterTest extends TestCase
{
    public function testWritesSecretsAndPlainValuesAndRemovesByKey(): void
    {
        $repository = new InMemorySettingValueRepository();
        $writer     = new RepositorySettingRowWriter($repository);
        $at         = new DateTimeImmutable('2026-11-10');

        $writer->writeRows([
            new StoredSettingRow('mailer', 'dsn', null, true, 'smtp://REDACTED-TEST', $at),
            new StoredSettingRow('mailer', 'from', 'ops@example.test', false, null, $at),
        ]);
        $rows = $repository->findBySection('mailer');
        self::assertSame('smtp://REDACTED-TEST', $rows[0]->getSecret());
        self::assertSame('ops@example.test', $rows[1]->getValue());

        // Saved and removed in the same call: the save wins.
        $writer->writeRows([new StoredSettingRow('mailer', 'from', 'new@example.test', false, null, $at)], ['mailer.dsn', 'mailer.from']);
        $rows = $repository->findBySection('mailer');
        self::assertCount(1, $rows);
        self::assertSame('new@example.test', $rows[0]->getValue());

        $writer->writeRows([]);
        self::assertCount(1, $repository->findAll());
    }

    public function testASecretWithoutItsValueIsRefused(): void
    {
        $row = unserialize(serialize(new StoredSettingRow('mailer', 'dsn', null, true, 'x', new DateTimeImmutable())));
        self::assertInstanceOf(StoredSettingRow::class, $row);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Instance setting row "mailer.dsn" is a secret without its value');
        (new RepositorySettingRowWriter(new InMemorySettingValueRepository()))->writeRows([$row]);
    }

    #[DataProvider('badKeys')]
    public function testAMalformedKeyIsRefused(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('expected "section.field"');
        (new RepositorySettingRowWriter(new InMemorySettingValueRepository()))->writeRows([], [$key]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badKeys(): iterable
    {
        yield 'no dot' => ['mailer'];
        yield 'empty section' => ['.dsn'];
        yield 'empty field' => ['mailer.'];
        yield 'two dots' => ['a.b.c'];
    }
}
