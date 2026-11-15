<?php

declare(strict_types=1);

namespace Nowo\InstanceSettingsBundle\Tests\Integration\Functional;

use Nowo\InstanceSettingsBundle\Portability\ConfigPortability;
use Nowo\InstanceSettingsBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\InstanceSettingsBundle\Tests\Support\RecordingLogger;

use function array_column;

/**
 * 1.19 (Beacon spec 239): what a rolled-back transaction leaves behind. Both tests fail on 1.18.1.
 *
 * - The request memo: values read inside a host transaction that rolls back were served for the rest of the request (or
 *   console command, or Messenger message of a worker with kernel reset off) although they were never committed.
 * - The audit line `Instance settings saved.`: written for a save inside a host transaction that then rolled back.
 */
final class LoadReadinessMemoTest extends KernelTestCase
{
    public function testTheMemoForgetsWhatARolledBackTransactionRead(): void
    {
        $kernel     = $this->bootKernel();
        $settings   = $this->settings($kernel);
        $connection = $this->connection($kernel);

        $connection->beginTransaction();
        $settings->save('general', ['site_name' => 'Uncommitted']);
        self::assertSame('Uncommitted', $settings->getString('general.site_name'), 'read inside the transaction');
        $connection->rollBack();

        self::assertSame('Acme', $settings->getString('general.site_name'), 'the rolled-back value is not served from the memo');
    }

    public function testTheMemoForgetsWhatARolledBackSavepointRead(): void
    {
        $kernel     = $this->bootKernel();
        $settings   = $this->settings($kernel);
        $connection = $this->connection($kernel);

        $connection->beginTransaction();
        $connection->createSavepoint('host_step');
        $settings->save('general', ['site_name' => 'Nested']);
        self::assertSame('Nested', $settings->getString('general.site_name'));
        $connection->rollbackSavepoint('host_step');
        self::assertSame('Acme', $settings->getString('general.site_name'), 'the savepoint rollback drops the memo');
        $connection->commit();
    }

    public function testTheAuditLineFollowsTheCommitOnly(): void
    {
        $kernel     = $this->bootKernel(services: ['logger' => RecordingLogger::class]);
        $settings   = $this->settings($kernel);
        $connection = $this->connection($kernel);
        $logger     = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);

        $connection->beginTransaction();
        $settings->save('general', ['site_name' => 'Rolled back']);
        $connection->rollBack();
        $settings->save('general', ['page_size' => 30]);

        $saved = [];
        foreach ($logger->records as $record) {
            if ($record['message'] === 'Instance settings saved.') {
                $saved[] = $record['context']['fields'] ?? null;
            }
        }
        self::assertSame(['page_size'], $saved, 'no audit line for the rolled-back save');
    }

    /** Audit QQ-L-2: the import's audit line follows the commit too. */
    public function testTheImportAuditLineFollowsTheCommitOnly(): void
    {
        $kernel      = $this->bootKernel(services: ['logger' => RecordingLogger::class]);
        $connection  = $this->connection($kernel);
        $portability = $this->container($kernel)->get(ConfigPortability::class);
        self::assertInstanceOf(ConfigPortability::class, $portability);
        $logger = $this->container($kernel)->get('logger');
        self::assertInstanceOf(RecordingLogger::class, $logger);
        $payload             = $portability->export(['general']);
        $payload['settings'] = ['general' => ['site_name' => 'Imported']];

        $connection->beginTransaction();
        $portability->import($payload);
        $connection->rollBack();
        self::assertNotContains('Instance configuration imported.', array_column($logger->records, 'message'), 'not for a rolled-back import');

        $portability->import($payload);
        self::assertContains('Instance configuration imported.', array_column($logger->records, 'message'));
    }
}
