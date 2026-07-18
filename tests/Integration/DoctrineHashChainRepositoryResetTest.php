<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use Nowo\VerifactuBundle\Tests\Kernel\DoctrineTestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineHashChainRepositoryResetTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DoctrineTestKernel::class;
    }

    public function testResetRemovesPersistedState(): void
    {
        self::bootKernel();
        $this->createSchema();

        /** @var DoctrineHashChainRepository $repository */
        $repository = self::getContainer()->get(DoctrineHashChainRepository::class);
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', str_repeat('A', 64)));
        $repository->reset('89890001K');

        self::assertNull($repository->getLastState('89890001K'));
        self::assertNull($repository->getLastHash('89890001K'));
    }

    public function testResetOnMissingIssuerIsNoOp(): void
    {
        self::bootKernel();
        $this->createSchema();

        /** @var DoctrineHashChainRepository $repository */
        $repository = self::getContainer()->get(DoctrineHashChainRepository::class);
        $repository->reset('00000000T');

        self::assertNull($repository->getLastState('00000000T'));
    }

    private function createSchema(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool    = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }
}
