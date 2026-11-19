<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * REQ-RUNTIME-003 rule 8 on MySQL, MariaDB and PostgreSQL: a database error raised by an AFTER_BILLING_RECORD_GENERATED
 * listener inside the host's transaction is rethrown. On PostgreSQL that error aborts the host's transaction (the
 * COMMIT would roll everything back), so a contained error would have returned a chained record whose head and host
 * rows never commit. After the host's rollback nothing of the unit is stored, the connection is usable, and the next
 * record chains onto the last committed head. Also checks the test sessions' bounded lock waits.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[Group('concurrency')]
final class HostTransactionListenerFailureTest extends TestCase
{
    public function testListenerDatabaseErrorInsideTheHostTransactionIsRethrownAndNothingCommits(): void
    {
        if (ConcurrencyKernel::databaseUrl() === '') {
            self::markTestSkipped('Set VERIFACTU_TEST_DATABASE_URL (MySQL, MariaDB or PostgreSQL) to run the concurrency tests.');
        }

        $kernel = new ConcurrencyKernel('test', false, 'doctrine');
        $kernel->boot();
        $container = $kernel->getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $doctrine->getManager();
        $schemaTool    = new SchemaTool($entityManager);
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        $connection = $entityManager->getConnection();
        /** @var BillingRecordProcessor $processor */
        $processor = $container->get(BillingRecordProcessor::SERVICE_NAME);

        $this->assertBoundedLockWait($connection->getDatabasePlatform()::class, $connection);

        $seed = $processor->process($this->record('SEED'));
        self::assertSame([], $seed['errors']);

        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        $listener   = static function () use ($connection): void {
            $connection->executeStatement('SELECT * FROM verifactu_table_that_does_not_exist');
        };
        $dispatcher->addListener(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $listener);

        $connection->beginTransaction();
        $connection->insert('host_invoice', ['number' => 'HOST-1']);
        $failure = null;
        try {
            $processor->process($this->record('FAC-ABORTED'));
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $connection->rollBack();
        $dispatcher->removeListener(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $listener);

        self::assertInstanceOf(DbalException::class, $failure, 'the listener database error must reach the host');
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-1'"));
        self::assertSame($seed['record']->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"), 'the head is still the last committed record');

        // The host replays its whole unit on the same connection: it chains onto the seed, once.
        $connection->beginTransaction();
        $connection->insert('host_invoice', ['number' => 'HOST-1']);
        $replayed = $processor->process($this->record('FAC-REPLAYED'));
        $connection->commit();

        self::assertSame([], $replayed['errors']);
        self::assertSame($seed['record']->hash, $replayed['record']->previousHash);
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-1'"));
        /** @var ChainVerifier $verifier */
        $verifier = $container->get(ChainVerifier::SERVICE_NAME);
        $result   = $verifier->verify('89890001K');
        self::assertTrue($result->isValid());
        self::assertSame(2, $result->checkedRecords);
        $kernel->shutdown();
    }

    private function assertBoundedLockWait(string $platform, Connection $connection): void
    {
        if (str_contains($platform, 'PostgreSQL')) {
            self::assertSame(ConcurrencyKernel::LOCK_WAIT_SECONDS . 's', (string) $connection->fetchOne('SHOW lock_timeout'));

            return;
        }
        self::assertSame(ConcurrencyKernel::LOCK_WAIT_SECONDS, (int) $connection->fetchOne('SELECT @@SESSION.innodb_lock_wait_timeout'));
    }

    private function record(string $number): BillingRecord
    {
        return new BillingRecord(RecordType::Alta, '89890001K', $number, '09-07-2026', 'F1', '21.00', '121.00', '', operationDescription: 'Listener failure');
    }
}
