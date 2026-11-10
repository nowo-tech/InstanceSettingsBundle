<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\RepositorySettingRowReader;
use Nowo\InstanceSettingsBundle\Repository\SettingRowReaderInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Repository\StoredSettingRow;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function print_r;

/**
 * 1.10 (DDD P2, pattern 2): persistence-neutral rows next to the entity-based repository port.
 */
final class RepositorySettingRowReaderTest extends TestCase
{
    public function testMapsTheRowsOfAHostRepository(): void
    {
        $at     = new DateTimeImmutable('2026-11-10 10:00:00');
        $plain  = (new SettingValue('general', 'site_name', $at))->storeValue('Acme', $at);
        $secret = (new SettingValue('mailer', 'dsn', $at))->storeSecret('smtp://u:p@host', $at);
        $lost   = (new SettingValue('mailer', 'token', $at))->storeSecret('x', $at);
        $lost->setSecretValue('ciphertext-without-terminator');
        $repository = new class([$plain, $secret, $lost]) implements SettingValueRepositoryInterface {
            /** @param list<SettingValue> $rows */
            public function __construct(private readonly array $rows)
            {
            }

            public function findAll(): array
            {
                return $this->rows;
            }

            public function findBySection(string $section): array
            {
                return array_values(array_filter($this->rows, static fn (SettingValue $row): bool => $row->getSection() === $section));
            }

            public function apply(array $save, array $remove): void
            {
            }

            public function transactional(callable $callback): mixed
            {
                return $callback();
            }
        };

        $reader = $this->reader($repository);

        $rows = $reader->findAllRows();
        self::assertCount(3, $rows);
        self::assertEquals(new StoredSettingRow('general', 'site_name', 'Acme', false, null, $at), $rows[0]);
        self::assertSame('general.site_name', $rows[0]->key());
        self::assertEquals(new StoredSettingRow('mailer', 'dsn', null, true, 'smtp://u:p@host', $at), $rows[1]);
        self::assertTrue($rows[2]->isSecret);
        self::assertNull($rows[2]->secret, 'undecryptable secret');

        $mailer = $reader->findRowsBySection('mailer');
        self::assertSame(['dsn', 'token'], [$mailer[0]->name, $mailer[1]->name]);
        self::assertSame([], $reader->findRowsBySection('unknown'));
    }

    public function testTheSecretIsLeftOutOfDumps(): void
    {
        $row  = new StoredSettingRow('mailer', 'dsn', null, true, 'smtp://u:p@host', new DateTimeImmutable());
        $dump = print_r($row, true);

        self::assertStringNotContainsString('smtp://u:p@host', $dump);
        self::assertStringContainsString('***', $dump);
        self::assertNull((new StoredSettingRow('general', 'a', 1, false, null, new DateTimeImmutable()))->__debugInfo()['secret']);
    }

    private function reader(SettingValueRepositoryInterface $repository): SettingRowReaderInterface
    {
        return new RepositorySettingRowReader($repository);
    }
}
