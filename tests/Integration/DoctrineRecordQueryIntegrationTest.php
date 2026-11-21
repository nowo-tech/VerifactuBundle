<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\BillingRecordCriteria;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Repository\BillingRecordQueryInterface;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordQuery;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Fixtures\HostEntity\HostInvoice;
use Nowo\VerifactuBundle\Tests\Kernel\DoctrineTestKernel;
use Nowo\VerifactuBundle\Tests\Support\RecordQueryScenarios;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Standard Symfony host (attribute mapping, default EntityManager): the Doctrine record query (1.10) on SQLite.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineRecordQueryIntegrationTest extends KernelTestCase
{
    use RecordQueryScenarios;

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

    protected function rqStore(): BillingRecordStoreInterface
    {
        /** @var BillingRecordStoreInterface $store */
        $store = self::getContainer()->get('nowo_verifactu.billing_record_store');

        return $store;
    }

    protected function rqQuery(): BillingRecordQueryInterface
    {
        $query = self::getContainer()->get('nowo_verifactu.billing_record_query');
        self::assertInstanceOf(DoctrineBillingRecordQuery::class, $query);

        return $query;
    }

    public function testTheQueryReadsWhatTheProcessorChainedAndLeavesTheHostUnitOfWorkAlone(): void
    {
        $hostInvoice = new HostInvoice('HOST-Q-1');
        $this->entityManager()->persist($hostInvoice);

        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);
        $first     = $processor->process($this->record('FAC-Q-001'));
        $second    = $processor->process($this->record('FAC-Q-002'));

        $query = $this->rqQuery();
        self::assertSame($first['record']->hash, $query->findByInvoice('89890001K', 'FAC-Q-001')?->hash);
        $views = $query->findLatestByInvoices('89890001K', ['FAC-Q-001', 'FAC-Q-002']);
        self::assertSame([$first['record']->hash, $second['record']->hash], [$views[0]->hash, $views[1]->hash]);
        self::assertSame(2, $query->count(new BillingRecordCriteria('89890001K')));

        // Read only: no transaction left open, no entity managed, the host's pending insert untouched
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
        self::assertArrayNotHasKey(BillingRecordEntry::class, $this->entityManager()->getUnitOfWork()->getIdentityMap());
        self::assertTrue($this->entityManager()->getUnitOfWork()->isScheduledForInsert($hostInvoice));
    }

    public function testDoctrineViewsCarryTheWriteTime(): void
    {
        $before = new DateTimeImmutable('-1 minute');
        $this->rqSeed();

        $view = $this->rqQuery()->findByPosition('89890001K', 1);
        self::assertInstanceOf(DateTimeImmutable::class, $view?->recordedAt);
        self::assertGreaterThanOrEqual($before, $view->recordedAt);
        self::assertNull($view->submittedAt);
    }

    public function testInvoiceListsLongerThanOneInClauseAreChunked(): void
    {
        $this->rqSeed();
        $numbers   = array_map(static fn (int $i): string => 'MISSING-' . $i, range(1, DoctrineBillingRecordQuery::INVOICE_CHUNK + 10));
        $numbers[] = 'FAC-002';

        $views = $this->rqQuery()->findLatestByInvoices('89890001K', $numbers);
        self::assertCount(1, $views);
        self::assertSame(2, $views[0]->position);
    }

    public function testWorkerModeRepeatedReadsKeepNoState(): void
    {
        // Production-like kernel: without debug, no query collector keeps every SQL statement in memory
        self::ensureKernelShutdown();
        self::bootKernel(['debug' => false]);
        $this->createSchema();
        $this->rqSeed();
        $query = $this->rqQuery();
        $read  = static function () use ($query): void {
            self::assertCount(5, $query->page(new BillingRecordCriteria('89890001K')));
            self::assertCount(1, $query->page(new BillingRecordCriteria('B12345674')));
            self::assertSame('FAC-002', $query->findByInvoice('89890001K', 'FAC-002')?->invoiceSeriesNumber);
        };
        for ($i = 0; $i < 50; ++$i) {
            $read();
        }
        gc_collect_cycles();
        $memory = memory_get_usage();

        for ($i = 0; $i < 1000; ++$i) {
            $read();
        }
        gc_collect_cycles();

        self::assertLessThan(256 * 1024, memory_get_usage() - $memory);
        self::assertSame([], $this->entityManager()->getUnitOfWork()->getIdentityMap());
    }

    public function testWithoutAnEntityManagerForTheRecordEntityTheQueryFailsClearly(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No Doctrine ORM entity manager is configured for');
        (new DoctrineBillingRecordQuery($registry))->count(new BillingRecordCriteria('89890001K'));
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
            operationDescription: 'Record query invoice',
        );
    }

    private function createSchema(): void
    {
        $schemaTool = new SchemaTool($this->entityManager());
        $metadata   = $this->entityManager()->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
