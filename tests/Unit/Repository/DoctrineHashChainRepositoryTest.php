<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordStore;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineHashChainRepositoryTest extends TestCase
{
    public function testThrowsWhenNoOrmManagerIsConfigured(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(BillingRecordHashChain::class)->willReturn(null);

        $this->expectException(LogicException::class);
        (new DoctrineHashChainRepository($registry))->getLastState('89890001K');
    }

    public function testClosedManagerNotOwnedByRegistryIsNotReset(): void
    {
        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturn(false);
        $closed->method('createQuery')->willThrowException(new RuntimeException('closed'));

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($closed);
        $registry->method('getManagers')->willReturn([]);
        $registry->expects(self::never())->method('resetManager');

        $this->expectException(RuntimeException::class);
        (new DoctrineHashChainRepository($registry))->reset('89890001K');
    }

    public function testRecordStoreThrowsWhenNoOrmManagerIsConfigured(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->with(BillingRecordEntry::class)->willReturn(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(BillingRecordEntry::class);
        iterator_to_array((new DoctrineBillingRecordStore($registry))->findByIssuer('89890001K'));
    }

    public function testAFailedRollbackNeverHidesTheOriginalError(): void
    {
        $original   = new RuntimeException('deadlock');
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('rollBack')->willThrowException(new RuntimeException('SAVEPOINT does not exist'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willThrowException($original);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        try {
            (new DoctrineHashChainRepository($registry))->withChainLock('89890001K', static fn (): bool => true);
            self::fail('The original error must be rethrown');
        } catch (RuntimeException $exception) {
            self::assertSame($original, $exception);
        }
    }

    public function testALostHostTransactionClosesTheConnectionAndAsksToRestartTheUnitOfWork(): void
    {
        $original   = new RuntimeException('deadlock');
        $calls      = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(2);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('rollBack')->willThrowException(new RuntimeException('SAVEPOINT DOCTRINE_2 does not exist'));
        $connection->expects(self::atLeastOnce())->method('close')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'close';
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willThrowException($original);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        try {
            (new DoctrineHashChainRepository($registry))->withChainLock('89890001K', static fn (): bool => true);
            self::fail('ChainTransactionLostException expected');
        } catch (ChainTransactionLostException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertStringContainsString('Restart your unit of work', $exception->getMessage());
        }
        self::assertSame('close', $calls[0] ?? null, 'the lost connection is closed first');
    }

    public function testANestedMysqlDeadlockIsALostTransactionEvenWhenTheNestedRollbackSucceeds(): void
    {
        // DBAL 3 without savepoints: the nested rollBack() only marks the transaction rollback-only and succeeds.
        $original   = new RuntimeException('wrapped', 0, new DeadlockException(new class('Deadlock found', '40001', 1213) extends AbstractException {}, null));
        $calls      = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(2);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        // The savepoint is never rolled back: the only rollBack() is the empty one on the new session, after close().
        $connection->method('rollBack')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'rollBack';
        });
        $connection->expects(self::atLeastOnce())->method('close')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'close';
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willThrowException($original);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        try {
            (new DoctrineHashChainRepository($registry))->withChainLock('89890001K', static fn (): bool => true);
            self::fail('ChainTransactionLostException expected');
        } catch (ChainTransactionLostException $exception) {
            self::assertSame($original, $exception->getPrevious());
        }
        self::assertSame('close', $calls[0] ?? null, 'the lost connection is closed first');
    }

    public function testANestedPostgresqlDeadlockRollsBackToTheSavepoint(): void
    {
        // PostgreSQL keeps the transaction (aborted until the savepoint is rolled back): no lost transaction.
        $original   = new DeadlockException(new class('deadlock detected', '40P01', 7) extends AbstractException {}, null);
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(2);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('close');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willThrowException($original);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        $this->expectExceptionObject($original);
        (new DoctrineHashChainRepository($registry))->withChainLock('89890001K', static fn (): bool => true);
    }

    public function testANestedMysqlLockWaitTimeoutIsALostTransaction(): void
    {
        // innodb_rollback_on_timeout ON ends the whole transaction; every retryable error is treated as lost.
        $original   = new LockWaitTimeoutException(new class('Lock wait timeout exceeded', 'HY000', 1205) extends AbstractException {}, null);
        $calls      = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(2);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new MariaDBPlatform());
        // The savepoint is never rolled back: the only rollBack() is the empty one on the new session, after close().
        $connection->method('rollBack')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'rollBack';
        });
        $connection->expects(self::atLeastOnce())->method('close')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'close';
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willThrowException($original);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        try {
            (new DoctrineHashChainRepository($registry))->withChainLock('89890001K', static fn (): bool => true);
            self::fail('ChainTransactionLostException expected');
        } catch (ChainTransactionLostException $exception) {
            self::assertSame($original, $exception->getPrevious());
        }
        self::assertSame('close', $calls[0] ?? null, 'the lost connection is closed first');
    }

    /**
     * @return iterable<string, array{AbstractPlatform, bool}>
     */
    public static function headReadPlatforms(): iterable
    {
        yield 'MySQL: the transaction is gone, close' => [new MySQLPlatform(), true];
        yield 'MariaDB: the transaction is gone, close' => [new MariaDBPlatform(), true];
        yield 'PostgreSQL: the aborted transaction stays open for the host rollback' => [new PostgreSQLPlatform(), false];
    }

    #[DataProvider('headReadPlatforms')]
    public function testAFailingHeadReadInsideTheTransactionClosesOnlyAGoneTransaction(AbstractPlatform $platform, bool $closed): void
    {
        $original   = new RuntimeException('current transaction is aborted');
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects($closed ? self::once() : self::never())->method('close');
        // PostgreSQL: the open, aborted transaction is marked rollback-only (a catch-and-commit fails loudly).
        $connection->expects($closed ? self::never() : self::once())->method('setRollbackOnly');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('createQueryBuilder')->willThrowException($original);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        try {
            (new DoctrineHashChainRepository($registry))->verifyHeadInTransaction('89890001K', 'ABC');
            self::fail('ChainTransactionLostException expected');
        } catch (ChainTransactionLostException $exception) {
            self::assertSame($original, $exception->getPrevious());
            self::assertSame($closed, $exception->connectionClosed);
            self::assertStringContainsString($closed ? 'connection was closed' : 'Roll it back', $exception->getMessage());
        }
    }

    public function testALockWaitTimeoutInsideTheBundlesOwnOuterLockRollsBackToTheSavepoint(): void
    {
        // The batch submitter's global flow-control lock around the issuer lock: no host transaction, so a lock-wait
        // timeout is not a lost transaction (UO2-5); a deadlock still is.
        $original   = new LockWaitTimeoutException(new class('Lock wait timeout exceeded', 'HY000', 1205) extends AbstractException {}, null);
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturnOnConsecutiveCalls(1, 2);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new MySQLPlatform());
        $connection->expects(self::once())->method('rollBack');
        $connection->expects(self::never())->method('close');

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $entityManager->method('getClassMetadata')->willThrowException($original);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);

        $repository = new DoctrineHashChainRepository($registry);
        // One own chain lock is already open (the outer global lock).
        (new ReflectionProperty(DoctrineHashChainRepository::class, 'ownLocks'))->setValue($repository, 1);

        $caught = null;
        try {
            $repository->withChainLock('89890001K', static fn (): bool => true);
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        self::assertSame($original, $caught, 'the lock-wait timeout is rethrown as it is');
        self::assertSame(1, (new ReflectionProperty(DoctrineHashChainRepository::class, 'ownLocks'))->getValue($repository), 'back to the outer lock');
    }

    public function testAFailedSavepointIsBalancedByClosingTheConnection(): void
    {
        // PostgreSQL inside an aborted host transaction: SAVEPOINT fails after DBAL counted the level, and ROLLBACK TO
        // SAVEPOINT fails too. The connection is closed (nesting level back to 0) instead of leaving it one level off.
        $original   = new RuntimeException('current transaction is aborted, commands ignored until end of transaction block');
        $calls      = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getTransactionNestingLevel')->willReturn(1);
        $connection->method('beginTransaction')->willThrowException($original);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('rollBack')->willThrowException(new RuntimeException('savepoint "DOCTRINE_2" does not exist'));
        $connection->expects(self::atLeastOnce())->method('close')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'close';
        });

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($entityManager);
        $repository = new DoctrineHashChainRepository($registry);

        $caught = null;
        try {
            $repository->withChainLock('89890001K', static fn (): bool => true);
        } catch (Throwable $exception) {
            $caught = $exception;
        }
        self::assertInstanceOf(ChainTransactionLostException::class, $caught);
        self::assertSame($original, $caught->getPrevious());
        self::assertSame(0, (new ReflectionProperty(DoctrineHashChainRepository::class, 'ownLocks'))->getValue($repository));
        self::assertSame('close', $calls[0] ?? null, 'the lost connection is closed first');
    }

    public function testSetRollbackOnlyMarksOnlyAnOpenTransaction(): void
    {
        foreach ([true, false] as $active) {
            $connection = $this->createMock(Connection::class);
            $connection->method('isTransactionActive')->willReturn($active);
            $connection->expects($active ? self::once() : self::never())->method('setRollbackOnly');
            $entityManager = $this->createMock(EntityManagerInterface::class);
            $entityManager->method('getConnection')->willReturn($connection);
            $registry = $this->createMock(ManagerRegistry::class);
            $registry->method('getManagerForClass')->willReturn($entityManager);

            (new DoctrineHashChainRepository($registry))->setRollbackOnly();
        }
    }
}
