<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use LogicException;
use Nowo\InstanceSettingsBundle\Entity\SettingValue;
use Nowo\InstanceSettingsBundle\Repository\RepositorySettingRowReader;
use Nowo\InstanceSettingsBundle\Repository\SettingRowReaderInterface;
use Nowo\InstanceSettingsBundle\Repository\SettingValueRepositoryInterface;
use Nowo\InstanceSettingsBundle\Repository\StoredSettingRow;
use PHPUnit\Framework\TestCase;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

use function array_filter;
use function array_values;
use function get_object_vars;
use function json_decode;
use function json_encode;
use function ob_get_clean;
use function ob_start;
use function print_r;
use function serialize;
use function unserialize;
use function var_dump;
use function var_export;

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
        self::assertNull($rows[0]->secret());
        self::assertEquals(new StoredSettingRow('mailer', 'dsn', null, true, 'smtp://u:p@host', $at), $rows[1]);
        self::assertSame('smtp://u:p@host', $rows[1]->secret());
        self::assertTrue($rows[2]->isSecret);
        self::assertNull($rows[2]->secret(), 'undecryptable secret');

        $mailer = $reader->findRowsBySection('mailer');
        self::assertSame(['dsn', 'token'], [$mailer[0]->name, $mailer[1]->name]);
        self::assertSame([], $reader->findRowsBySection('unknown'));
    }

    /**
     * Audit KI-I-M1: the decrypted secret reaches no serialisation or dump channel.
     */
    public function testTheSecretReachesNoDumpOrSerialisationChannel(): void
    {
        $secret = 'smtp://u:REDACTED-TEST@host';
        $row    = new StoredSettingRow('mailer', 'dsn', null, true, $secret, new DateTimeImmutable('2026-11-10 10:00:00'));

        $cloner  = new VarCloner();
        $dumper  = new CliDumper();
        $outputs = [
            'print_r'         => print_r($row, true),
            'var_export'      => var_export($row, true),
            'json_encode'     => (string) json_encode($row),
            'serialize'       => serialize($row),
            'array cast'      => print_r((array) $row, true),
            'get_object_vars' => print_r(get_object_vars($row), true),
            'VarDumper'       => (string) $dumper->dump($cloner->cloneVar($row), true),
        ];
        ob_start();
        var_dump($row);
        $outputs['var_dump'] = (string) ob_get_clean();

        foreach ($outputs as $channel => $output) {
            self::assertStringNotContainsString('REDACTED-TEST', $output, $channel);
        }
        self::assertSame('***', json_decode($outputs['json_encode'], true)['secret']);
        self::assertSame('2026-11-10T10:00:00+00:00', json_decode($outputs['json_encode'], true)['updatedAt']);
        self::assertStringContainsString('***', $outputs['var_dump']);
        self::assertSame($secret, $row->secret());

        $restored = unserialize($outputs['serialize']);
        self::assertInstanceOf(StoredSettingRow::class, $restored);
        self::assertSame(['mailer', 'dsn', true, null], [$restored->section, $restored->name, $restored->isSecret, $restored->secret()]);

        $plain = new StoredSettingRow('general', 'a', 1, false, null, new DateTimeImmutable());
        self::assertNull($plain->__debugInfo()['secret']);
        self::assertNull($plain->jsonSerialize()['secret']);
    }

    public function testRowsCannotBeCloned(): void
    {
        $this->expectException(LogicException::class);
        $row  = new StoredSettingRow('mailer', 'dsn', null, true, 'x', new DateTimeImmutable());
        $copy = clone $row;
        self::fail('Cloned: ' . $copy->name);
    }

    private function reader(SettingValueRepositoryInterface $repository): SettingRowReaderInterface
    {
        return new RepositorySettingRowReader($repository);
    }
}
