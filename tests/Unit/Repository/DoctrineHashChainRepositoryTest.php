<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordStore;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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
}
