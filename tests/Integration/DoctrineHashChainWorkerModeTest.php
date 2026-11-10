<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\DBAL\Exception as DbalException;
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

    public function testStoringTheHeadNeverFlushesThePendingEntitiesOfTheHost(): void
    {
        $repository = $this->repository();
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', str_repeat('A', 64)));

        // A pending (not yet flushed) insert of the host: a flush by the bundle would write it (and fail on the unique key)
        $pending = new BillingRecordHashChain('89890001K', 'DUP', '09-07-2026', str_repeat('D', 64));
        $this->entityManager()->persist($pending);

        $repository->storeLastState(new HashChainState('B12345678', 'FAC-900', '09-07-2026', str_repeat('E', 64)));
        $repository->storeLastState(new HashChainState('89890001K', 'FAC-002', '09-07-2026', str_repeat('F', 64)));

        self::assertTrue($this->entityManager()->isOpen());
        self::assertTrue($this->entityManager()->getUnitOfWork()->isScheduledForInsert($pending), 'The host entity must still be pending');
        self::assertSame('FAC-002', $repository->getLastState('89890001K')?->invoiceSeriesNumber);
        self::assertSame(str_repeat('E', 64), $repository->getLastState('b12345678')?->hash);
    }

    public function testAClosedHostManagerIsNeitherResetNorNeededByTheRepository(): void
    {
        $repository = $this->repository();
        $this->entityManager()->close();

        $repository->storeLastState(new HashChainState('89890001K', 'FAC-001', '09-07-2026', str_repeat('A', 64)));
        self::assertSame('FAC-001', $repository->getLastState('89890001K')?->invoiceSeriesNumber);
        $repository->reset('89890001K');
        self::assertNull($repository->getLastState('89890001K'));

        self::assertFalse($this->entityManager()->isOpen(), 'The bundle must not reset the host EntityManager');
    }

    public function testAFailingStatementIsRethrownAndTheChainLockRollsBack(): void
    {
        $repository = $this->repository();
        $this->entityManager()->getConnection()->executeStatement('DROP TABLE ' . $this->tableName());

        try {
            $repository->withChainLock('89890001K', static fn (): bool => true);
            self::fail('A failing SELECT … FOR UPDATE must be rethrown');
        } catch (DbalException) {
        }

        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
    }

    private function simulateOtherWorkerWrite(string $invoiceSeriesNumber, string $hash): void
    {
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE ' . $this->tableName() . ' SET invoice_series_number = ?, hash = ? WHERE issuer_nif = ?',
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

    /**
     * Native SQL follows the mapped (possibly prefixed, REQ-ORM-002) table name instead of hard-coding it.
     */
    private function tableName(): string
    {
        return $this->entityManager()->getClassMetadata(BillingRecordHashChain::class)->getTableName();
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
