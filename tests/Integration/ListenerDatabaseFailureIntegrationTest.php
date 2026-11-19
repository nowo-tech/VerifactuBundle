<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Kernel\DoctrineTestKernel;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * REQ-RUNTIME-003 rule 8 on a real connection (SQLite): a database error raised by an AFTER_BILLING_RECORD_GENERATED
 * listener inside the host's transaction is rethrown (the host rolls back, no chain head survives); outside a
 * transaction the head is already committed and the failure stays contained in `event_errors`.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class ListenerDatabaseFailureIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $entityManager = $this->entityManager();
        $schemaTool    = new SchemaTool($entityManager);
        $metadata      = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        $connection = $entityManager->getConnection();
        /** @var EventDispatcherInterface $dispatcher */
        $dispatcher = self::getContainer()->get('event_dispatcher');
        $dispatcher->addListener(VerifactuEvents::AFTER_BILLING_RECORD_GENERATED, static function () use ($connection): void {
            $connection->executeStatement('SELECT * FROM verifactu_table_that_does_not_exist');
        });
    }

    public function testRepositoryReportsTheTransactionState(): void
    {
        $repository = $this->repository();
        $connection = $this->entityManager()->getConnection();

        self::assertFalse($repository->isInsideTransaction());
        $connection->beginTransaction();
        self::assertTrue($repository->isInsideTransaction());
        $connection->rollBack();
        self::assertFalse($repository->isInsideTransaction());
    }

    public function testHeadVerificationInsideTheTransaction(): void
    {
        $repository = $this->repository();
        $connection = $this->entityManager()->getConnection();
        $connection->beginTransaction();
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-2026-904', '09-07-2026', str_repeat('a', 64)));

        $repository->verifyHeadInTransaction('89890001k', str_repeat('A', 64));
        self::assertSame(1, $connection->getTransactionNestingLevel(), 'a head that is there leaves the transaction alone');

        try {
            $repository->verifyHeadInTransaction('89890001K', str_repeat('B', 64));
            self::fail('ChainTransactionLostException expected');
        } catch (ChainTransactionLostException $exception) {
            self::assertNull($exception->getPrevious());
            self::assertFalse($exception->connectionClosed, 'SQLite: only MySQL / MariaDB connections are closed');
        }
        self::assertSame(1, $connection->getTransactionNestingLevel(), 'the host rolls its transaction back itself');
        self::assertTrue($connection->isRollbackOnly(), 'a host that commits anyway fails loudly');
        $connection->rollBack();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function autoCommitModes(): iterable
    {
        yield 'auto_commit on' => [true];
        yield 'auto_commit off' => [false];
    }

    /** UO5-4: closing a lost connection clears its rollback-only mark, also with auto_commit off. */
    #[DataProvider('autoCommitModes')]
    public function testClosingALostConnectionClearsTheRollbackOnlyMark(bool $autoCommit): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->setAutoCommit($autoCommit);
        $connection->beginTransaction();
        $connection->setRollbackOnly();

        (new ReflectionMethod(DoctrineHashChainRepository::class, 'closeLostConnection'))->invoke($this->repository(), $connection);

        // auto_commit off: DBAL keeps its implicit transaction (level 1) open again, unmarked.
        self::assertSame($autoCommit ? 0 : 1, $connection->getTransactionNestingLevel());
        if (!$autoCommit) {
            self::assertFalse($connection->isRollbackOnly());
            $connection->commit();
            $connection->setAutoCommit(true);
        }
        self::assertSame(0, $connection->getTransactionNestingLevel());
    }

    public function testNestedChainLocksThatThrowLeaveNoOwnLockAndNoTransaction(): void
    {
        $repository = $this->repository();
        $ownLocks   = new ReflectionProperty(DoctrineHashChainRepository::class, 'ownLocks');
        $failure    = new RuntimeException('inner failure');

        $caught = null;
        try {
            $repository->withChainLock('*', static fn (): mixed => $repository->withChainLock('89890001K', static function () use ($failure): never {
                throw $failure;
            }));
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        self::assertSame($failure, $caught, 'the inner failure comes out as it is');
        self::assertSame(0, $ownLocks->getValue($repository));
        self::assertSame(0, $this->entityManager()->getConnection()->getTransactionNestingLevel());

        // A host transaction afterwards is seen as the host's again, and a normal record chains.
        $this->entityManager()->getConnection()->beginTransaction();
        $result = $repository->withChainLock('89890001K', static fn (?HashChainState $head = null): ?HashChainState => $head);
        self::assertNull($result);
        $this->entityManager()->getConnection()->commit();
        self::assertSame(0, $ownLocks->getValue($repository));
    }

    public function testDatabaseFailureInsideTheHostTransactionIsRethrownAndNothingIsChained(): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->beginTransaction();

        $failure = null;
        try {
            $this->processor()->process($this->record('FAC-2026-901'));
        } catch (Throwable $exception) {
            $failure = $exception;
        }
        $connection->rollBack();

        self::assertInstanceOf(DbalException::class, $failure);
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertNull($this->repository()->getLastState('89890001K'), 'the host rolled back: no chain head survives');

        // The connection is usable afterwards: the next unit (outside a transaction) chains from an empty head.
        $result = $this->processor()->process($this->record('FAC-2026-902'));
        self::assertNull($result['record']->previousHash);
        self::assertSame($result['record']->hash, $this->repository()->getLastState('89890001K')?->hash);
    }

    public function testDatabaseFailureOutsideATransactionStaysContainedAndTheHeadIsKept(): void
    {
        $result = $this->processor()->process($this->record('FAC-2026-903'));

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['event_errors'] ?? []);
        self::assertInstanceOf(DbalException::class, $result['event_errors'][0]['exception']);
        self::assertSame($result['record']->hash, $this->repository()->getLastState('89890001K')?->hash);
    }

    private function record(string $seriesNumber): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            $seriesNumber,
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Doctrine Test Issuer',
            operationDescription: 'Invoice',
        );
    }

    private function processor(): BillingRecordProcessor
    {
        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);

        return $processor;
    }

    private function repository(): DoctrineHashChainRepository
    {
        /** @var DoctrineHashChainRepository $repository */
        $repository = self::getContainer()->get(DoctrineHashChainRepository::class);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
