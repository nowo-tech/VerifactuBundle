<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Kernel\DoctrineTestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration tests for Doctrine-backed hash chain persistence.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineHashChainRepositoryIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
        $this->createSchema();
    }

    public function testRepositoryPersistsAndReloadsHashChainState(): void
    {
        /** @var DoctrineHashChainRepository $repository */
        $repository = self::getContainer()->get(DoctrineHashChainRepository::class);

        $repository->storeLastState(new HashChainState(
            '89890001K',
            'FAC-2026-001',
            '09-07-2026',
            'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
        ));

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $state = $repository->getLastState('89890001K');
        self::assertNotNull($state);
        self::assertSame('FAC-2026-001', $state->invoiceSeriesNumber);
        self::assertSame('09-07-2026', $state->issueDate);
        self::assertSame(
            'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789',
            $state->hash,
        );
    }

    public function testProcessorUpdatesHashChainInDatabase(): void
    {
        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);
        /** @var HashChainRepositoryInterface $repository */
        $repository = self::getContainer()->get(HashChainRepositoryInterface::class);

        $first = $processor->process(new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-001',
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Doctrine Test Issuer',
            operationDescription: 'First invoice',
        ));

        self::assertSame([], $first['errors']);
        self::assertNotNull($first['record']->hash);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $second = $processor->process(new BillingRecord(
            RecordType::Alta,
            '89890001K',
            'FAC-2026-002',
            '09-07-2026',
            'F1',
            '10.50',
            '60.50',
            '2026-07-09T17:00:00+02:00',
            issuerName: 'Doctrine Test Issuer',
            operationDescription: 'Second invoice',
        ));

        self::assertSame([], $second['errors']);
        self::assertSame($first['record']->hash, $second['record']->previousHash);

        $state = $repository->getLastState('89890001K');
        self::assertNotNull($state);
        self::assertSame('FAC-2026-002', $state->invoiceSeriesNumber);
        self::assertSame($second['record']->hash, $state->hash);
    }

    public function testStoreLastHashUpdatesExistingEntity(): void
    {
        /** @var DoctrineHashChainRepository $repository */
        $repository = self::getContainer()->get(DoctrineHashChainRepository::class);

        $repository->storeLastState(new HashChainState(
            '89890001K',
            'FAC-2026-010',
            '09-07-2026',
            str_repeat('A', 64),
        ));
        $repository->storeLastHash('89890001K', str_repeat('B', 64));

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        self::assertSame(str_repeat('B', 64), $repository->getLastHash('89890001K'));
        $state = $repository->getLastState('89890001K');
        self::assertNotNull($state);
        self::assertSame('FAC-2026-010', $state->invoiceSeriesNumber);
    }

    private function createSchema(): void
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $schemaTool    = new SchemaTool($entityManager);
        $schemaTool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }
}
