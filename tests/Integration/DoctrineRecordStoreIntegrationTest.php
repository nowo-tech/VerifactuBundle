<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\BillingRecordStoreInterface;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Service\ChainVerifier;
use Nowo\VerifactuBundle\Tests\Fixtures\HostEntity\HostInvoice;
use Nowo\VerifactuBundle\Tests\Kernel\DoctrineTestKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Standard Symfony host (attribute-mapped host entity, default EntityManager): the record store and the chain head
 * are written without flushing the host's unit of work, and join the host's transaction.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineRecordStoreIntegrationTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return DoctrineTestKernel::class;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $schemaTool = new SchemaTool($this->entityManager());
        $metadata   = $this->entityManager()->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testProcessDoesNotFlushThePendingEntitiesOfTheHost(): void
    {
        $hostInvoice = new HostInvoice('HOST-1');
        $this->entityManager()->persist($hostInvoice);

        $result = $this->processor()->process($this->record('FAC-2026-001', '12.3', '62.3'));

        self::assertSame([], $result['errors']);
        self::assertTrue($this->entityManager()->getUnitOfWork()->isScheduledForInsert($hostInvoice));
        self::assertSame(0, $this->countRows('host_invoice'));
        self::assertSame(1, $this->countRows($this->recordTable()));

        $this->entityManager()->flush();
        self::assertSame(1, $this->countRows('host_invoice'));
    }

    public function testChainHeadAndRecordJoinTheHostTransaction(): void
    {
        $connection = $this->entityManager()->getConnection();
        $connection->beginTransaction();
        $this->processor()->process($this->record('FAC-2026-001', '21.00', '121.00'));
        $connection->rollBack();

        self::assertNull($this->hashChain()->getLastState('89890001K'));
        self::assertSame([], [...$this->store()->findByIssuer('89890001K')]);

        $connection->beginTransaction();
        $this->processor()->process($this->record('FAC-2026-002', '21.00', '121.00'));
        $connection->commit();

        self::assertSame('FAC-2026-002', $this->hashChain()->getLastState('89890001K')?->invoiceSeriesNumber);
        self::assertCount(1, [...$this->store()->findByIssuer('89890001K')]);
    }

    public function testStoredChainIsVerifiedAndSubmissionOutcomeIsRecorded(): void
    {
        $first  = $this->processor()->process($this->record('FAC-2026-001', '21', '121.3'), true);
        $second = $this->processor()->process($this->record('FAC-2026-002', '0', '50.00'), true);
        $this->processor()->process($this->record('FAC-2026-003', '2.10', '12.10'), false, false);

        self::assertSame([], $first['errors']);
        self::assertArrayHasKey('submission', $first);
        self::assertTrue($first['submission']['success']);

        $stored = [...$this->store()->findByIssuer(' 89890001k ')];
        self::assertCount(2, $stored, 'A dry run is not stored');
        self::assertSame([1, 2], array_map(static fn (StoredBillingRecord $s): int => $s->position, $stored));
        self::assertSame(StoredBillingRecord::STATUS_ACCEPTED, $stored[0]->submissionStatus);
        self::assertSame('TESTCSV', $stored[0]->submissionReference);
        self::assertSame('121.30', $stored[0]->record->totalAmount);
        self::assertSame('0.00', $stored[1]->record->totalTaxAmount);
        self::assertSame($first['record']->hash, $stored[1]->record->previousHash);
        self::assertSame($second['record']->xml, $stored[1]->record->xml);
        self::assertSame('Doctrine Test Issuer', $stored[1]->record->issuerName);
        self::assertNull($stored[1]->record->signedXml);

        /** @var ChainVerifier $verifier */
        $verifier = self::getContainer()->get(ChainVerifier::class);
        self::assertTrue($verifier->verify('89890001K')->isValid());

        self::assertNotNull(self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('nowo:verifactu:verify-chain'));
        self::assertSame(Command::SUCCESS, $tester->execute(['--issuer' => '89890001K']));

        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE ' . $this->recordTable() . ' SET total_amount = ? WHERE position = 1',
            ['999.99'],
        );
        self::assertFalse($verifier->verify('89890001K')->isValid());
        self::assertSame(Command::FAILURE, $tester->execute(['--issuer' => '89890001K']));
    }

    public function testStoreEnabledOnAnExistingChainContinuesFromTheOldHead(): void
    {
        // A head written by 1.4.1 (or with the store off): the first stored record chains onto it
        $oldHead = str_repeat('C', 64);
        $this->hashChain()->storeLastState(new HashChainState('89890001K', 'FAC-2025-999', '31-12-2025', $oldHead));

        $first  = $this->processor()->process($this->record('FAC-2026-001', '21.00', '121.00'));
        $second = $this->processor()->process($this->record('FAC-2026-002', '21.00', '121.00'));

        self::assertSame($oldHead, $first['record']->previousHash);
        $stored = [...$this->store()->findByIssuer('89890001K')];
        self::assertSame([1, 2], array_map(static fn (StoredBillingRecord $s): int => $s->position, $stored));
        self::assertSame($first['record']->hash, $second['record']->previousHash);

        /** @var ChainVerifier $verifier */
        $verifier = self::getContainer()->get(ChainVerifier::class);
        $result   = $verifier->verify('89890001K');
        self::assertTrue($result->isValid());
        self::assertSame($oldHead, $result->continuesFrom);
        self::assertTrue($verifier->verify('89890001K', $oldHead)->isValid());
    }

    public function testAppendOutsideATransactionAndAnEmptyGenerationTimeStampedInTheLock(): void
    {
        $result = $this->processor()->process(new BillingRecord(RecordType::Alta, '89890001K', 'FAC-2026-001', '09-07-2026', 'F1', '21.00', '121.00', ''));
        self::assertSame([], $result['errors']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+0[12]:00$/', $result['record']->generatedAt);
        self::assertSame('Doctrine Test Issuer', $result['record']->issuerName, 'The issuer name of the provider is resolved once and kept on the record');

        // A direct append without a transaction (no locking read) continues after the last position
        $next = $result['record']->withHash(str_repeat('E', 64), (string) $result['record']->hash);
        self::assertFalse($this->entityManager()->getConnection()->isTransactionActive());
        self::assertSame(2, $this->store()->append($next));
    }

    public function testFailedSubmissionIsMarkedFailed(): void
    {
        $result = $this->processor()->process($this->record('FAC-2026-001', '21.00', '121.00'));
        $this->store()->markSubmitted('89890001K', (string) $result['record']->hash, false);

        $stored = [...$this->store()->findByIssuer('89890001K')];
        self::assertSame(StoredBillingRecord::STATUS_FAILED, $stored[0]->submissionStatus);
        self::assertNull($stored[0]->submissionReference);
    }

    private function record(string $invoiceSeriesNumber, string $tax, string $total): BillingRecord
    {
        return new BillingRecord(
            RecordType::Alta,
            '89890001K',
            $invoiceSeriesNumber,
            '09-07-2026',
            'F1',
            $tax,
            $total,
            '2026-07-09T16:00:00+02:00',
            issuerName: 'Doctrine Test Issuer',
            operationDescription: 'Record store invoice',
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }

    private function recordTable(): string
    {
        return $this->entityManager()->getClassMetadata(BillingRecordEntry::class)->getTableName();
    }

    private function processor(): BillingRecordProcessor
    {
        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);

        return $processor;
    }

    private function store(): BillingRecordStoreInterface
    {
        /** @var BillingRecordStoreInterface $store */
        $store = self::getContainer()->get('nowo_verifactu.billing_record_store');

        return $store;
    }

    private function hashChain(): HashChainRepositoryInterface
    {
        /** @var HashChainRepositoryInterface $repository */
        $repository = self::getContainer()->get(HashChainRepositoryInterface::class);

        return $repository;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        return $entityManager;
    }
}
