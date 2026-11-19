<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
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

    /** UO4-1: a host that catches the lost-head exception before the submission and commits anyway fails loudly. */
    public function testCatchingTheLostHeadAndCommittingFailsLoudly(): void
    {
        [$kernel, $connection, $processor, $dispatcher, $seed] = $this->bootWithSeed();
        $dispatcher->addListener(VerifactuEvents::BEFORE_AEAT_SUBMISSION, $this->headBreaker($connection));
        $connection->beginTransaction();
        $connection->insert('host_invoice', ['number' => 'HOST-CC1']);
        $lost = null;
        try {
            $processor->process($this->record('FAC-CC1'), submitToAeat: true);
        } catch (ChainTransactionLostException $exception) {
            $lost = $exception;
        }
        self::assertInstanceOf(ChainTransactionLostException::class, $lost);
        $commit = $this->commitFailure($connection);
        // PostgreSQL keeps the aborted transaction open and marks it rollback-only; MySQL / MariaDB closed it.
        if ($lost->connectionClosed) {
            self::assertInstanceOf(DbalException::class, $commit, 'no transaction left to commit');
        } else {
            $this->assertRollbackOnly($commit);
        }
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-CC1'"));
        self::assertSame($seed['record']->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));
        $kernel->shutdown();
    }

    /** UO4-1: after the submission, catch-and-commit fails loudly, also from an inner transactional() savepoint. */
    #[DataProvider('innerTransactional')]
    public function testCatchingTheSubmittedRecordAndCommittingFailsLoudly(bool $innerTransactional): void
    {
        [$kernel, $connection, $processor, $dispatcher, $seed] = $this->bootWithSeed();
        $dispatcher->addListener(VerifactuEvents::AFTER_AEAT_SUBMISSION, static function () use ($connection): void {
            $connection->executeStatement('SELECT * FROM verifactu_table_that_does_not_exist');
        });
        $connection->beginTransaction();
        $connection->insert('host_invoice', ['number' => 'HOST-CC2']);
        $caught = null;
        try {
            $unit = function () use ($processor): void {
                $processor->process($this->record('FAC-CC2'), submitToAeat: true);
            };
            $innerTransactional ? $connection->transactional(static fn () => $unit()) : $unit();
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $failure = $caught instanceof Throwable ? SubmittedRecordNotCommittedException::find($caught) : null;
        self::assertInstanceOf(SubmittedRecordNotCommittedException::class, $failure);
        self::assertTrue($connection->isTransactionActive(), 'the host transaction is still open');
        $this->assertRollbackOnly($this->commitFailure($connection));
        $connection->rollBack();
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-CC2'"));

        self::assertTrue($processor->persistSubmittedRecord($failure->record, $failure->submission));
        self::assertSame($failure->record->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));
        self::assertSame($seed['record']->hash, $failure->record->previousHash);
        $kernel->shutdown();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function innerTransactional(): iterable
    {
        yield 'directly in the host transaction' => [false];
        yield 'in an inner transactional() savepoint' => [true];
    }

    /** UO4-2 / UO4-3: an exception that escapes without a rollback, then the next request (kernel reset off). */
    public function testTheNextRequestRecoversWhenTheHostDidNotRollBack(): void
    {
        [$kernel, $connection, $processor, $dispatcher] = $this->bootWithSeed();
        $breaker                                        = $this->headBreaker($connection);
        $dispatcher->addListener(VerifactuEvents::BEFORE_AEAT_SUBMISSION, $breaker);
        $connection->beginTransaction();
        try {
            $processor->process($this->record('FAC-LEAK'), submitToAeat: true);
        } catch (ChainTransactionLostException) {
            // The host lets it escape: no rollback.
        }
        $dispatcher->removeListener(VerifactuEvents::BEFORE_AEAT_SUBMISSION, $breaker);

        // A repair inside a still-open transaction is refused (UO4-3).
        if ($connection->isTransactionActive()) {
            try {
                $processor->persistSubmittedRecord($this->record('FAC-LEAK'), ['success' => true]);
                self::fail('LogicException expected');
            } catch (LogicException) {
            }
        }

        // Next request. MySQL / MariaDB: the bundle closed the connection, nothing is left. PostgreSQL: the aborted,
        // rollback-only transaction is still there (the host must roll back, or run worker hygiene); the bundle's chain
        // lock fails inside it but always leaves the nesting level where it found it, never one level off (UO4-2).
        $level = $connection->getTransactionNestingLevel();
        if ($level > 0) {
            $caught = null;
            try {
                $processor->process($this->record('FAC-NEXT-1'));
            } catch (Throwable $exception) {
                $caught = $exception;
            }
            self::assertInstanceOf(Throwable::class, $caught, 'nothing chains inside the aborted transaction');
            $closed = $caught instanceof ChainTransactionLostException && $caught->connectionClosed;
            self::assertSame($closed ? 0 : $level, $connection->getTransactionNestingLevel(), 'balanced nesting level');
            // Worker hygiene (or the host's own error handling) rolls the leftover transaction back.
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
        $next = $processor->process($this->record('FAC-NEXT-2'));
        self::assertSame([], $next['errors']);
        self::assertSame($next['record']->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));
        $kernel->shutdown();
    }

    /** UO5-2: outside any host transaction, a listener that leaks a failed transaction never fails a committed record. */
    public function testAListenerThatLeaksATransactionNeverFailsACommittedRecord(): void
    {
        [$kernel, $connection, $processor, $dispatcher] = $this->bootWithSeed();
        $leak                                           = static function () use ($connection): void {
            $connection->beginTransaction();
            $connection->executeStatement('SELECT * FROM verifactu_table_that_does_not_exist');
        };
        $dispatcher->addListener(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $leak);

        $result = $processor->process($this->record('FAC-LEAKED-TX'), submitToAeat: true);
        $dispatcher->removeListener(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, $leak);

        // Contained (the record is committed), sent and returned; the leaked transaction is the listener's problem.
        self::assertSame([], $result['errors']);
        self::assertInstanceOf(DbalException::class, $result['event_errors'][0]['exception'] ?? null);
        self::assertSame('SANDBOX-' . $result['record']->hash, $result['submission']['reference'] ?? null);
        for ($guard = 0; $guard < 4 && $connection->isTransactionActive(); ++$guard) {
            $connection->rollBack();
        }
        self::assertSame(1, (int) $connection->fetchOne("SELECT COUNT(*) FROM verifactu_billing_record WHERE invoice_series_number = 'FAC-LEAKED-TX'"));
        self::assertSame($result['record']->hash, $connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));
        $kernel->shutdown();
    }

    /**
     * A BEFORE_AEAT_SUBMISSION listener that breaks the host transaction and hides it: on PostgreSQL a swallowed error
     * (the transaction is aborted), on MySQL / MariaDB a moved head (the head check then finds another head).
     */
    private function headBreaker(Connection $connection): callable
    {
        $postgreSql = str_contains($connection->getDatabasePlatform()::class, 'PostgreSQL');

        return static function () use ($connection, $postgreSql): void {
            try {
                $connection->executeStatement($postgreSql
                    ? 'SELECT * FROM verifactu_table_that_does_not_exist'
                    : "UPDATE verifactu_hash_chain SET hash = '" . str_repeat('F', 64) . "' WHERE issuer_nif = '89890001K'");
            } catch (DbalException) {
                // Hidden from the bundle.
            }
        };
    }

    /**
     * DBAL 4 CommitFailedRollbackOnly / DBAL 3 ConnectionException::commitFailedRollbackOnly().
     */
    private function assertRollbackOnly(?Throwable $failure): void
    {
        self::assertInstanceOf(DbalException::class, $failure);
        self::assertStringContainsStringIgnoringCase('rollback only', $failure->getMessage());
    }

    private function commitFailure(Connection $connection): ?Throwable
    {
        try {
            $connection->commit();
        } catch (Throwable $exception) {
            return $exception;
        }

        return null;
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
