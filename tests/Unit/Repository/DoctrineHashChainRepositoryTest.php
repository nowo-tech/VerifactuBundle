<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
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
}
