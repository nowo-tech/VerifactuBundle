<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Exception\SubmittedRecordNotCommittedException;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testDatabaseErrorAfterTheAeatSubmissionCarriesTheRecordAndTheRepairStoresIt(): void
    {
        [$kernel, $connection, $processor, $dispatcher, $seed] = $this->bootWithSeed();
        $listener                                              = static function () use ($connection): void {
            $connection->executeStatement('SELECT * FROM verifactu_table_that_does_not_exist');
        };
        $dispatcher->addListener(VerifactuEvents::AFTER_AEAT_SUBMISSION, $listener);

        $connection->beginTransaction();
        $connection->insert('host_invoice', ['number' => 'HOST-2']);
        $failure = null;
        try {
            $processor->process($this->record('FAC-SENT'), submitToAeat: true);
        } catch (SubmittedRecordNotCommittedException $exception) {
            $failure = $exception;
        }
        // The transaction is still open on every platform here (PostgreSQL: aborted, the head check does not close
        // it; MySQL / MariaDB: the failed statement did not end it): the host rolls it back.
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        $dispatcher->removeListener(VerifactuEvents::AFTER_AEAT_SUBMISSION, $listener);

        self::assertInstanceOf(SubmittedRecordNotCommittedException::class, $failure, 'the host must learn that the AEAT has a record whose head did not commit');
        self::assertSame('SANDBOX-' . $failure->record->hash, $failure->submission['reference'] ?? null);
        self::assertSame($seed['record']->hash, $failure->record->previousHash);
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-2'"));
        self::assertSame($seed['record']->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));

        // Repair: exactly the record the AEAT received becomes the head, with its answer; the next record chains on it.
        self::assertTrue($processor->persistSubmittedRecord($failure->record, $failure->submission));
        self::assertSame($failure->record->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));
        self::assertSame('SANDBOX-' . $failure->record->hash, $connection->fetchOne('SELECT submission_reference FROM verifactu_billing_record WHERE hash = ?', [$failure->record->hash]));
        $next = $processor->process($this->record('FAC-NEXT'));
        self::assertSame($failure->record->hash, $next['record']->previousHash);
        /** @var ChainVerifier $verifier */
        $verifier = $kernel->getContainer()->get(ChainVerifier::SERVICE_NAME);
        self::assertTrue($verifier->verify('89890001K')->isValid());
        self::assertSame(3, $verifier->verify('89890001K')->checkedRecords);
        $kernel->shutdown();
    }

    public function testASwallowedDatabaseErrorBeforeTheAeatSubmissionIsFoundByTheHeadCheck(): void
    {
        [$kernel, $connection, $processor, $dispatcher, $seed] = $this->bootWithSeed();
        $dispatcher->addListener(VerifactuEvents::BEFORE_AEAT_SUBMISSION, static function () use ($connection): void {
            try {
                $connection->executeStatement('SELECT * FROM verifactu_table_that_does_not_exist');
            } catch (DbalException) {
                // A listener that hides the error from the bundle.
            }
        });

        $connection->beginTransaction();
        $connection->insert('host_invoice', ['number' => 'HOST-3']);
        $isPostgreSql = str_contains($connection->getDatabasePlatform()::class, 'PostgreSQL');
        try {
            $result = $processor->process($this->record('FAC-SWALLOWED'), submitToAeat: true);
            // MySQL / MariaDB: a failed statement does not end the transaction, the head is still there.
            self::assertFalse($isPostgreSql, 'PostgreSQL aborted the transaction: the head check must refuse to submit');
            self::assertSame('SANDBOX-' . $result['record']->hash, $result['submission']['reference'] ?? null);
            $connection->commit();
            self::assertSame($result['record']->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));
        } catch (ChainTransactionLostException $exception) {
            self::assertTrue($isPostgreSql);
            self::assertInstanceOf(DbalException::class, $exception->getPrevious());
            // PostgreSQL: the aborted transaction stays open, the host rolls it back (UO3-1).
            self::assertFalse($exception->connectionClosed);
            self::assertTrue($connection->isTransactionActive());
            $connection->rollBack();
            self::assertSame($seed['record']->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-3'"));
        }
        $kernel->shutdown();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function afterSubmissionFailures(): iterable
    {
        // A database error in the listener (PostgreSQL aborts the transaction; MySQL / MariaDB keep it).
        yield 'database error' => ['SELECT * FROM verifactu_table_that_does_not_exist'];
        // The head moved inside the transaction (MySQL / MariaDB close the connection: transactional()'s rollBack() then
        // throws NoActiveTransaction with the bundle's exception as its previous one).
        yield 'head gone' => ["UPDATE verifactu_hash_chain SET hash = '" . str_repeat('F', 64) . "' WHERE issuer_nif = '89890001K'"];
    }

    /** UO3-1: the host wraps process() in Connection::transactional() and still finds and repairs the record. */
    #[DataProvider('afterSubmissionFailures')]
    public function testInsideTransactionalTheHostFindsTheSubmittedRecordAndRepairsTheChain(string $listenerStatement): void
    {
        [$kernel, $connection, $processor, $dispatcher, $seed] = $this->bootWithSeed();
        $listener                                              = static function () use ($connection, $listenerStatement): void {
            $connection->executeStatement($listenerStatement);
        };
        $dispatcher->addListener(VerifactuEvents::AFTER_AEAT_SUBMISSION, $listener);

        $caught = null;
        try {
            $connection->transactional(function (Connection $connection) use ($processor): void {
                $connection->insert('host_invoice', ['number' => 'HOST-TX']);
                $processor->process($this->record('FAC-TX'), submitToAeat: true);
            });
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $dispatcher->removeListener(VerifactuEvents::AFTER_AEAT_SUBMISSION, $listener);

        self::assertInstanceOf(Throwable::class, $caught);
        $failure = SubmittedRecordNotCommittedException::find($caught);
        self::assertInstanceOf(SubmittedRecordNotCommittedException::class, $failure, 'the documented find() reaches it through ' . $caught::class);
        self::assertFalse($connection->isTransactionActive(), 'transactional() ended the transaction');
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-TX'"));
        self::assertSame($seed['record']->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));

        self::assertTrue($processor->persistSubmittedRecord($failure->record, $failure->submission));
        self::assertSame($failure->record->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));
        /** @var ChainVerifier $verifier */
        $verifier = $kernel->getContainer()->get(ChainVerifier::SERVICE_NAME);
        self::assertTrue($verifier->verify('89890001K')->isValid());
        self::assertSame(2, $verifier->verify('89890001K')->checkedRecords);
        $kernel->shutdown();
    }

    /**
     * @return array{ConcurrencyKernel, Connection, BillingRecordProcessor, EventDispatcherInterface, array{record: BillingRecord, errors: list<string>}}
     */
    private function bootWithSeed(): array
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
        /** @var BillingRecordProcessor $processor */
        $processor = $container->get(BillingRecordProcessor::SERVICE_NAME);
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = $container->get('event_dispatcher');
        $seed       = $processor->process($this->record('SEED'));
        self::assertSame([], $seed['errors']);

        return [$kernel, $entityManager->getConnection(), $processor, $dispatcher, ['record' => $seed['record'], 'errors' => $seed['errors']]];
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
