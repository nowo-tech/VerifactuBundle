<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Concurrency;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Exception\SubmittedRecordNotCommittedException;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\SubmissionQueueInterface;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Real lock-wait timeouts against a second connection that holds a row lock (MySQL, MariaDB, PostgreSQL; audit UO2,
 * probes adapted from the auditor's): the answer the host gets and how transaction-doctrine classifies it.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[Group('concurrency')]
final class LockWaitConflictTest extends TestCase
{
    /** Short lock wait of the sessions under test (seconds). */
    private const WAIT = 2;

    private ConcurrencyKernel $kernel;

    private Connection $connection;

    private Connection $holder;

    private BillingRecordProcessor $processor;

    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        if (ConcurrencyKernel::databaseUrl() === '') {
            self::markTestSkipped('Set VERIFACTU_TEST_DATABASE_URL (MySQL, MariaDB or PostgreSQL) to run the concurrency tests.');
        }
        $this->kernel = new ConcurrencyKernel('test', false, 'doctrine');
        $this->kernel->boot();
        $container = $this->kernel->getContainer();
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        /** @var EntityManagerInterface $entityManager */
        $entityManager = $doctrine->getManager();
        $schemaTool    = new SchemaTool($entityManager);
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        $this->connection = $entityManager->getConnection();
        /** @var BillingRecordProcessor $processor */
        $processor       = $container->get(BillingRecordProcessor::SERVICE_NAME);
        $this->processor = $processor;
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher       = $container->get('event_dispatcher');
        $this->dispatcher = $dispatcher;
        self::assertSame([], $this->processor->process($this->record('SEED'))['errors']);

        $this->holder = DriverManager::getConnection((new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']))->parse(ConcurrencyKernel::databaseUrl()));
        $this->holder->executeStatement($this->lockWaitStatement(ConcurrencyKernel::LOCK_WAIT_SECONDS));
        $this->connection->executeStatement($this->lockWaitStatement(self::WAIT));
    }

    protected function tearDown(): void
    {
        if (isset($this->holder)) {
            if ($this->holder->isTransactionActive()) {
                $this->holder->rollBack();
            }
            $this->holder->close();
        }
        if (isset($this->kernel)) {
            $this->kernel->shutdown();
        }
    }

    /** UO2-1: a lock-wait timeout after the AEAT submission must never look like a retry-safe conflict. */
    public function testALockWaitAfterTheSubmissionIsNeverARetrySafeConflict(): void
    {
        $this->connection->insert('host_invoice', ['number' => 'LOCKED']);
        $id = (int) $this->connection->fetchOne("SELECT id FROM host_invoice WHERE number = 'LOCKED'");
        $this->holder->beginTransaction();
        $this->holder->fetchAllAssociative('SELECT * FROM host_invoice WHERE id = ? FOR UPDATE', [$id]);
        $connection = $this->connection;
        $this->dispatcher->addListener(VerifactuEvents::AFTER_AEAT_SUBMISSION, static function () use ($connection, $id): void {
            $connection->executeStatement("UPDATE host_invoice SET number = 'LOCKED-2' WHERE id = ?", [$id]);
        });

        $session = $this->sessionId();
        $this->connection->beginTransaction();
        $this->connection->insert('host_invoice', ['number' => 'HOST-LW']);
        $caught = null;
        try {
            $this->processor->process($this->record('FAC-LW'), submitToAeat: true);
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $this->holder->rollBack();
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        self::assertInstanceOf(SubmittedRecordNotCommittedException::class, $caught);
        self::assertSame($session, $this->sessionId(), 'the connection was not closed (the transaction was still there to roll back)');
        self::assertTrue((new TransientFailureClassifier())->isTransient($caught->databaseError ?? new RuntimeException()), 'the cause is a lock-wait timeout');
        $this->assertLockWaitTimeout($caught->databaseError);
        self::assertNull($caught->getPrevious());
        self::assertFalse((new TransientFailureClassifier())->isTransient($caught));
        self::assertNull(TransientFailureClassifier::findConflict($caught));
        if ($this->isPostgreSql()) {
            // PostgreSQL aborted the transaction: the head check found it gone as well (UO2-6).
            self::assertInstanceOf(ChainTransactionLostException::class, $caught->headLoss);
        }
        self::assertTrue($this->processor->persistSubmittedRecord($caught->record, $caught->submission));
        self::assertSame($caught->record->hash, $this->connection->fetchOne("SELECT hash FROM verifactu_hash_chain WHERE issuer_nif = '89890001K'"));
    }

    /** UO-VF-3: a lock-wait timeout on the chain row inside the host transaction. */
    public function testAChainLockWaitInsideTheHostTransaction(): void
    {
        $this->holder->beginTransaction();
        $this->holder->fetchAllAssociative("SELECT * FROM verifactu_hash_chain WHERE issuer_nif = '89890001K' FOR UPDATE");

        $session = $this->sessionId();
        $this->connection->beginTransaction();
        $this->connection->insert('host_invoice', ['number' => 'HOST-VF3']);
        $caught = null;
        try {
            $this->processor->process($this->record('FAC-VF3'));
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $this->holder->rollBack();
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        if ($this->isPostgreSql()) {
            // The savepoint rollback keeps the host's transaction usable: the lock-wait error comes out as it is.
            $this->assertLockWaitTimeout($caught);
            self::assertSame($session, $this->sessionId(), 'PostgreSQL: the connection was not closed');
        } else {
            self::assertInstanceOf(ChainTransactionLostException::class, $caught);
            self::assertInstanceOf(LockWaitTimeoutException::class, $caught->getPrevious());
        }
        self::assertInstanceOf(Throwable::class, $caught);
        self::assertTrue((new TransientFailureClassifier())->isTransient($caught), 'a CONFLICT_001 for transaction-doctrine');
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM host_invoice WHERE number = 'HOST-VF3'"));
    }

    /** UO2-5: the batch submitter's own nested locks (global flow control) give a plain retryable error. */
    public function testAGlobalFlowControlLockWaitIsAPlainRetryableError(): void
    {
        $container = $this->kernel->getContainer();
        /** @var SubmissionQueueInterface $queue */
        $queue = $container->get('nowo_verifactu.billing_record_store');
        /** @var ManagerRegistry $doctrine */
        $doctrine = $container->get('doctrine');
        $heads    = $doctrine->getRepository(BillingRecordHashChain::class);
        self::assertInstanceOf(HashChainRepositoryInterface::class, $heads);
        $submitter = new BillingRecordBatchSubmitter($queue, new ScriptedBatchClient(), $heads, new EventDispatcher(), null, null, null, 7, 300, 0, 'global');
        self::assertSame(BatchSubmissionResult::STATE_SENT, $submitter->submitPending('89890001K')->state);
        self::assertSame([], $this->processor->process($this->record('PENDING'))['errors']);

        $session = $this->sessionId();
        $this->holder->beginTransaction();
        $this->holder->fetchAllAssociative("SELECT * FROM verifactu_hash_chain WHERE issuer_nif = '89890001K' FOR UPDATE");
        $caught = null;
        try {
            $submitter->submitPending('89890001K');
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        $this->holder->rollBack();

        // A plain lock-wait error (DBAL maps MySQL / MariaDB 1205 to LockWaitTimeoutException; PostgreSQL 55P03 stays a
        // DriverException that transaction-doctrine classifies by its SQLSTATE), never ChainTransactionLostException.
        $this->assertLockWaitTimeout($caught);
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertSame($session, $this->sessionId(), 'a lock wait in its own nested lock never closes the connection');
        // The record is still waiting and goes with the next run.
        self::assertSame(BatchSubmissionResult::STATE_SENT, $submitter->submitPending('89890001K')->state);
    }

    private function assertLockWaitTimeout(?Throwable $failure): void
    {
        self::assertInstanceOf(DriverException::class, $failure);
        if ($this->isPostgreSql()) {
            self::assertSame('55P03', $failure->getSQLState());
        } else {
            self::assertInstanceOf(LockWaitTimeoutException::class, $failure, 'a DBAL RetryableException');
        }
        self::assertTrue((new TransientFailureClassifier())->isTransient($failure));
    }

    /**
     * The database session of the connection under test (a closed and reconnected connection gets another one).
     */
    private function sessionId(): string
    {
        return (string) $this->connection->fetchOne($this->isPostgreSql() ? 'SELECT pg_backend_pid()' : 'SELECT CONNECTION_ID()');
    }

    private function isPostgreSql(): bool
    {
        return str_starts_with(ConcurrencyKernel::databaseUrl(), 'pgsql');
    }

    private function lockWaitStatement(int $seconds): string
    {
        return $this->isPostgreSql() ? "SET lock_timeout = '" . $seconds . "s'" : 'SET SESSION innodb_lock_wait_timeout = ' . $seconds;
    }

    private function record(string $number): BillingRecord
    {
        return new BillingRecord(RecordType::Alta, '89890001K', $number, '09-07-2026', 'F1', '21.00', '121.00', '', operationDescription: 'Lock wait');
    }
}
