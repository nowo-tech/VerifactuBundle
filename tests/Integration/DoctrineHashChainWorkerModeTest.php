<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\VerifactuBundle\Entity\BillingRecordHashChain;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\DoctrineHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Kernel\DoctrineTestKernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Doctrine hash chain under FrankenPHP worker mode without services_resetter:
 * the EntityManager is never cleared, another worker writes the same row, a flush fails.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineHashChainWorkerModeTest extends KernelTestCase
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
    }

    public function testLastStateIsReadFreshEvenWhenTheEntityIsInTheIdentityMap(): void
    {
        $repository = $this->repository();
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', str_repeat('A', 64)));

        $managed = $this->entityManager()->getRepository(BillingRecordHashChain::class)->findOneBy(['issuerNif' => '89890001K']);
        self::assertInstanceOf(BillingRecordHashChain::class, $managed);

        $this->simulateOtherWorkerWrite('FAC-002', str_repeat('B', 64));

        $state = $repository->getLastState('89890001K');
        self::assertNotNull($state);
        self::assertSame('FAC-002', $state->invoiceSeriesNumber);
        self::assertSame(str_repeat('B', 64), $state->hash);
    }

    public function testProcessorChainsToTheRecordWrittenByAnotherWorker(): void
    {
        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);

        // Request 1 on this worker
        $first = $processor->process($this->record('FAC-2026-001'));
        self::assertSame([], $first['errors']);

        // Another worker appends FAC-2026-002 to the chain meanwhile
        $otherWorkerHash = str_repeat('C', 64);
        $this->simulateOtherWorkerWrite('FAC-2026-002', $otherWorkerHash);

        // Request 2 on this worker: no EntityManager::clear(), no services_resetter
        $third = $processor->process($this->record('FAC-2026-003'));
        self::assertSame([], $third['errors']);
        self::assertSame($otherWorkerHash, $third['record']->previousHash);

        $state = $this->repository()->getLastState('89890001K');
        self::assertNotNull($state);
        self::assertSame('FAC-2026-003', $state->invoiceSeriesNumber);
        self::assertFalse($this->entityManager()->getUnitOfWork()->size() > 0, 'Hash chain entities must not stay managed');
    }

    public function testFailedFlushResetsClosedEntityManagerSoTheWorkerRecovers(): void
    {
        $repository = $this->repository();
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', str_repeat('A', 64)));

        // A conflicting pending insert makes the next flush fail on the unique constraint
        $this->entityManager()->persist(new BillingRecordHashChain('89890001K', 'DUP', '09-07-2026', str_repeat('D', 64)));

        try {
            $repository->storeLastState(new HashChainState('B12345678', 'FAC-900', '09-07-2026', str_repeat('E', 64)));
            self::fail('The unique constraint violation must be rethrown');
        } catch (UniqueConstraintViolationException) {
        }

        self::assertTrue($this->entityManager()->isOpen());

        $repository->storeLastState(new HashChainState('89890001K', 'FAC-002', '09-07-2026', str_repeat('F', 64)));
        $state = $repository->getLastState('89890001K');
        self::assertNotNull($state);
        self::assertSame('FAC-002', $state->invoiceSeriesNumber);
    }

    public function testFailedResetQueryResetsClosedEntityManager(): void
    {
        $repository = $this->repository();
        $this->entityManager()->getConnection()->executeStatement('DROP TABLE verifactu_hash_chain');
        $this->entityManager()->close();

        try {
            $repository->reset('89890001K');
            self::fail('A failing DELETE must be rethrown');
        } catch (DbalException) {
        }

        self::assertTrue($this->entityManager()->isOpen());
    }

    private function simulateOtherWorkerWrite(string $invoiceSeriesNumber, string $hash): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE verifactu_hash_chain SET invoice_series_number = ?, hash = ? WHERE issuer_nif = ?',
            [$invoiceSeriesNumber, $hash, '89890001K'],
        );
    }

    private function record(string $invoiceSeriesNumber): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            $invoiceSeriesNumber,
            '09-07-2026',
            'F1',
            '21.00',
            '121.00',
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Doctrine Test Issuer',
            operationDescription: 'Worker mode invoice',
        );
    }

    private function repository(): DoctrineHashChainRepository
    {
        /** @var DoctrineHashChainRepository $repository */
        $repository = self::getContainer()->get(DoctrineHashChainRepository::class);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var ManagerRegistry $registry */
        $registry      = self::getContainer()->get('doctrine');
        $entityManager = $registry->getManagerForClass(BillingRecordHashChain::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
