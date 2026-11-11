<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Integration;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use LogicException;
use Nowo\VerifactuBundle\Command\SubmitPendingCommand;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionOutcome;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordStore;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\SubmissionQueueInterface;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Tests\Kernel\DoctrineTestKernel;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * The Doctrine record store as the submission queue (claims, outcomes, stale claims) and the batch submitter wired by
 * the bundle (NullAeatSubmissionClient: every record accepted).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class DoctrineSubmissionQueueIntegrationTest extends KernelTestCase
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

    public function testClaimsOutcomesAndStaleClaims(): void
    {
        $this->chain(['F-1', 'F-2', 'F-3', 'F-3']);
        $queue = $this->queue();
        $now   = new DateTimeImmutable('2026-11-11 10:00:00');

        self::assertSame([], $queue->claimNext('12345678Z', 1000, 'nothing', $now, 300));
        self::assertSame(4, $queue->countPending('89890001k', $now, 300));
        self::assertSame(['89890001K'], $queue->issuersWithPendingRecords());
        self::assertFalse($queue->hasSubmissionInFlight('89890001K', $now, 300));

        // The second F-3 (same invoice id and operation) waits for the next submission.
        $claimed = $queue->claimNext('89890001K', 1000, 'claim-a', $now, 300);
        self::assertSame([1, 2, 3], array_map(static fn (StoredBillingRecord $s): int => $s->position, $claimed));
        self::assertSame([1, 1, 1], array_map(static fn (StoredBillingRecord $s): int => $s->submissionAttempts, $claimed));
        self::assertTrue($queue->hasSubmissionInFlight('89890001K', $now, 300));
        self::assertSame(1, $queue->countPending('89890001K', $now, 300));

        $updated = $queue->recordOutcomes('89890001K', 'claim-a', [
            new RecordSubmissionOutcome($claimed[0], RecordSubmissionStatus::Accepted, 'CSV-1'),
            new RecordSubmissionOutcome($claimed[1], RecordSubmissionStatus::Rejected, null, 1100, 'Valor incorrecto'),
            new RecordSubmissionOutcome($claimed[2], null, null, null, 'no line'),
        ], $now);
        self::assertCount(3, $updated);
        self::assertSame([], $queue->recordOutcomes('89890001K', 'someone-else', [new RecordSubmissionOutcome($claimed[0], RecordSubmissionStatus::Rejected)], $now));

        $stored = [...$this->store()->findByIssuer('89890001K')];
        self::assertSame([StoredBillingRecord::STATUS_ACCEPTED, StoredBillingRecord::STATUS_REJECTED, StoredBillingRecord::STATUS_PENDING, StoredBillingRecord::STATUS_PENDING], array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, $stored));
        self::assertSame(['CSV-1', null], [$stored[0]->submissionReference, $stored[1]->submissionReference]);
        self::assertSame([1100, 'Valor incorrecto'], [$stored[1]->submissionErrorCode, $stored[1]->submissionError]);
        $entry = $this->entityManager()->find(BillingRecordEntry::class, ['issuerNif' => '89890001K', 'position' => 1]);
        self::assertInstanceOf(BillingRecordEntry::class, $entry);
        self::assertSame(1, $entry->getSubmissionAttempts());
        self::assertNotNull($entry->getSubmittedAt());

        // A claim whose process died is taken over after the timeout; the stale claim can no longer store anything.
        $claimed = $queue->claimNext('89890001K', 1000, 'claim-b', $now, 300);
        self::assertSame([3], array_map(static fn (StoredBillingRecord $s): int => $s->position, $claimed));
        self::assertTrue($queue->hasSubmissionInFlight('89890001K', $now->modify('+299 seconds'), 300), 'The claim of F-3 is not stale yet');
        self::assertFalse($queue->hasSubmissionInFlight('89890001K', $now->modify('+301 seconds'), 300));
        self::assertSame(1, $queue->releaseClaim('89890001K', 'claim-b', 'timeout'));
        $later = $now->modify('+10 minutes');
        $queue->claimNext('89890001K', 1000, 'claim-d', $later, 300);
        $taken = $queue->claimNext('89890001K', 1000, 'claim-e', $later->modify('+301 seconds'), 300);
        self::assertSame([3], array_map(static fn (StoredBillingRecord $s): int => $s->position, $taken));
        self::assertSame(4, $taken[0]->submissionAttempts, 'Claims a, b, d and e');
        self::assertSame([], $queue->recordOutcomes('89890001K', 'claim-d', [new RecordSubmissionOutcome($taken[0], RecordSubmissionStatus::Accepted)], $later));
    }

    public function testTheSubmitterOverTheDoctrineQueueAndChainLock(): void
    {
        // The bundle wires the submitter and its command (here with the SOAP client of the test certificate, which a
        // test must not call: the submitter below uses the same queue and chain lock with a scripted client).
        self::assertInstanceOf(BillingRecordBatchSubmitter::class, self::getContainer()->get(BillingRecordBatchSubmitter::SERVICE_NAME));
        self::assertInstanceOf(SubmitPendingCommand::class, self::getContainer()->get(SubmitPendingCommand::class));

        $this->chain(['F-1', 'F-2']);
        /** @var HashChainRepositoryInterface $heads */
        $heads     = self::getContainer()->get(HashChainRepositoryInterface::class);
        $client    = new ScriptedBatchClient();
        $submitter = new BillingRecordBatchSubmitter($this->queue(), $client, $heads, new EventDispatcher());

        $result = $submitter->submitPending('89890001K');
        self::assertSame(2, $result->count(RecordSubmissionStatus::Accepted));
        self::assertSame([], $submitter->issuersWithPendingRecords());
        self::assertSame(['F-1', 'F-2'], array_map(static fn (BillingRecord $r): string => $r->invoiceSeriesNumber, $client->requests[0]));

        $connection = $this->entityManager()->getConnection();
        $connection->beginTransaction();
        try {
            $this->expectException(LogicException::class);
            $submitter->submitPending('89890001K');
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * @param list<string> $numbers
     */
    private function chain(array $numbers): void
    {
        /** @var BillingRecordProcessor $processor */
        $processor = self::getContainer()->get(BillingRecordProcessor::class);
        foreach ($numbers as $number) {
            $result = $processor->process(new BillingRecord(RecordType::Alta, '89890001K', $number, '09-07-2026', 'F1', '21.00', '121.00', '', operationDescription: 'Queue'));
            self::assertSame([], $result['errors']);
        }
    }

    private function queue(): SubmissionQueueInterface
    {
        return $this->store();
    }

    private function store(): DoctrineBillingRecordStore
    {
        /** @var DoctrineBillingRecordStore $store */
        $store = self::getContainer()->get('nowo_verifactu.billing_record_store');

        return $store;
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
