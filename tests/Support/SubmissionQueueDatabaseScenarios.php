<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\VerifactuBundle\Client\AeatResponseParser;
use Nowo\VerifactuBundle\Doctrine\DbalRowWriter;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionOutcome;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\DoctrineBillingRecordStore;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Submission\CacheFlowControl;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function in_array;

/**
 * Audit LJ regressions on the Doctrine record store, shared by the SQLite integration test and the server database
 * test (MySQL, MariaDB, PostgreSQL; tests/Concurrency, the `concurrency` CI job). The using class provides a fresh
 * schema before every test.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
trait SubmissionQueueDatabaseScenarios
{
    abstract protected function ljStore(): DoctrineBillingRecordStore;

    abstract protected function ljProcessor(): BillingRecordProcessor;

    abstract protected function ljHeads(): HashChainRepositoryInterface;

    abstract protected function ljEntityManager(): EntityManagerInterface;

    /**
     * LJ-M2: a claim counts no attempt; markTransmitted() counts one for the records of that claim only.
     */
    public function testAnAttemptIsCountedAtTransmissionOnTheDatabase(): void
    {
        $this->ljChain(['A-1', 'A-2', 'A-3']);
        $store = $this->ljStore();
        $now   = new DateTimeImmutable('2026-11-11 10:00:00');

        $claimed = $store->claimNext('89890001K', 2, 'claim-a', $now, 300);
        self::assertSame([0, 0], array_map(static fn (StoredBillingRecord $s): int => $s->submissionAttempts, $claimed));
        self::assertSame([0, 0, 0], $this->ljColumn('submissionAttempts'));

        self::assertSame(2, $store->markTransmitted('89890001k', 'claim-a'));
        self::assertSame(0, $store->markTransmitted('89890001K', 'nobody'));
        self::assertSame([1, 1, 0], $this->ljColumn('submissionAttempts'));

        // A claim that dies before sending, taken over after the timeout: still one attempt per transmission.
        self::assertSame(2, $store->releaseClaim('89890001K', 'claim-a'));
        $store->claimNext('89890001K', 3, 'died', $now, 300);
        $taken = $store->claimNext('89890001K', 3, 'claim-b', $now->modify('+301 seconds'), 300);
        self::assertSame([1, 1, 0], array_map(static fn (StoredBillingRecord $s): int => $s->submissionAttempts, $taken));
        $store->markTransmitted('89890001K', 'claim-b');
        self::assertSame([2, 2, 1], $this->ljColumn('submissionAttempts'));
    }

    /**
     * LJ-M2: the `unconfirmed` state is stored (it fits the status column) and is never waiting again.
     */
    public function testAnUnconfirmedDuplicateIsStoredOnTheDatabase(): void
    {
        $this->ljChain(['U-1']);
        $store   = $this->ljStore();
        $now     = new DateTimeImmutable('2026-11-11 10:00:00');
        $claimed = $store->claimNext('89890001K', 10, 'claim-u', $now, 300);

        self::assertCount(1, $store->recordOutcomes('89890001K', 'claim-u', [new RecordSubmissionOutcome($claimed[0], RecordSubmissionStatus::Unconfirmed, null, 3000, 'Duplicado (RegistroDuplicado: IdPeticionRegistroDuplicado X, EstadoRegistroDuplicado Correcta)')], $now));
        self::assertSame([StoredBillingRecord::STATUS_UNCONFIRMED], $this->ljColumn('submissionStatus'));
        self::assertSame(0, $store->countPending('89890001K', $now, 300));
        self::assertSame([], $store->issuersWithPendingRecords());
    }

    /**
     * LJ-M3: a refusal of the whole request adds a failure to each record and halves the next claim; a release without
     * counting keeps them; an answer resets them; markFailed() is terminal until requeueFailed().
     */
    public function testSubmissionFailuresSplitTheClaimAndFailTheRecordOnTheDatabase(): void
    {
        $this->ljChain(['S-1', 'S-2', 'S-3', 'S-4']);
        $store = $this->ljStore();
        $now   = new DateTimeImmutable('2026-11-11 10:00:00');

        self::assertCount(4, $store->claimNext('89890001K', 4, 'c1', $now, 300));
        self::assertSame(4, $store->releaseClaim('89890001K', 'c1', 'AEAT SOAP fault: x', true));
        self::assertSame([1, 1, 1, 1], $this->ljColumn('submissionFailures'));
        self::assertSame(array_fill(0, 4, 'AEAT SOAP fault: x'), $this->ljColumn('submissionError'));

        $half = $store->claimNext('89890001K', 4, 'c2', $now, 300);
        self::assertSame([1, 2], array_map(static fn (StoredBillingRecord $s): int => $s->position, $half));
        self::assertSame([1, 1], array_map(static fn (StoredBillingRecord $s): int => $s->submissionFailures, $half));
        self::assertSame(2, $store->releaseClaim('89890001K', 'c2'));
        self::assertSame([1, 1, 1, 1], $this->ljColumn('submissionFailures'), 'A release without counting keeps them');
        self::assertSame(array_fill(0, 4, 'AEAT SOAP fault: x'), $this->ljColumn('submissionError'), 'and the last error');

        $half = $store->claimNext('89890001K', 4, 'c3', $now, 300);
        $store->recordOutcomes('89890001K', 'c3', array_map(static fn (StoredBillingRecord $s): RecordSubmissionOutcome => new RecordSubmissionOutcome($s, RecordSubmissionStatus::Accepted, 'CSV'), $half), $now);
        self::assertSame([0, 0, 1, 1], $this->ljColumn('submissionFailures'));

        $alone = $store->claimNext('89890001K', 2, 'c4', $now, 300);
        self::assertSame([3], array_map(static fn (StoredBillingRecord $s): int => $s->position, $alone), '2 halved once');
        self::assertSame([], $store->markFailed('89890001K', 'nobody', 'x', $now));
        self::assertSame([strtoupper((string) $alone[0]->record->hash)], $store->markFailed('89890001K', 'c4', 'AEAT SOAP fault: y', $now));
        self::assertSame(['accepted', 'accepted', 'failed', 'pending'], $this->ljColumn('submissionStatus'));
        self::assertSame([0, 0, 2, 1], $this->ljColumn('submissionFailures'));
        self::assertSame(1, $store->countPending('89890001K', $now, 300));
        self::assertFalse($store->hasSubmissionInFlight('89890001K', $now, 300));

        self::assertSame(1, $store->requeueFailed('89890001K'));
        self::assertSame(['accepted', 'accepted', 'pending', 'pending'], $this->ljColumn('submissionStatus'));
        self::assertSame([0, 0, 0, 1], $this->ljColumn('submissionFailures'));
        self::assertSame(0, $store->requeueFailed('89890001K'));
    }

    /**
     * LJ-M3 end to end: a SoapFault for one record never blocks the issuer; the batch is split and only that record
     * ends failed.
     */
    public function testASoapFaultRecordIsIsolatedOnTheDatabase(): void
    {
        $this->ljChain(['E-1', 'E-2', 'E-3', 'E-4']);
        $answer = static fn (array $records): AeatBatchResponse => in_array('E-3', array_map(static fn (BillingRecord $r): string => $r->invoiceSeriesNumber, $records), true)
            ? (new AeatResponseParser())->parse('<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/"><env:Body><env:Fault><faultcode>env:Client</faultcode><faultstring>Codigo[4102]</faultstring></env:Fault></env:Body></env:Envelope>', 500)
            : ScriptedBatchClient::allAccepted($records);
        $client    = new ScriptedBatchClient(...array_fill(0, 20, $answer));
        $submitter = new BillingRecordBatchSubmitter($this->ljStore(), $client, $this->ljHeads(), new EventDispatcher(), null, new MockClock('2026-11-11 10:00:00'), null, 4, 300, 60, 'issuer', null, 1);

        for ($i = 0; $i < 20 && $submitter->submitPending('89890001K')->state !== BatchSubmissionResult::STATE_IDLE; ++$i) {
        }

        self::assertSame([4, 2, 2, 1, 1], array_map('count', $client->requests));
        self::assertSame(['accepted', 'accepted', 'failed', 'accepted'], $this->ljColumn('submissionStatus'));
        self::assertSame([2, 2, 3, 3], $this->ljColumn('submissionAttempts'), 'One attempt per request that carried the record');
    }

    /**
     * LJ-L1: the global scope takes a lock shared by every issuer and reserves the slot before the request: an issuer
     * checking while another issuer's request is in flight waits.
     */
    public function testTheGlobalScopeSerialisesIssuersOnTheDatabase(): void
    {
        $this->ljChain(['G-1']);
        $this->ljChain(['G-1'], '12345678Z');
        $flow   = new CacheFlowControl(new ArrayAdapter());
        $clock  = new MockClock('2026-11-11 10:00:00');
        $other  = new ScriptedBatchClient();
        $second = new BillingRecordBatchSubmitter($this->ljStore(), $other, $this->ljHeads(), new EventDispatcher(), $flow, $clock, null, 1000, 300, 60, 'global');
        $during = null;
        $racing = new ScriptedBatchClient(static function (array $records) use (&$during, $second): AeatBatchResponse {
            $during = $second->submitPending('12345678Z');

            return ScriptedBatchClient::allAccepted($records);
        });
        $first = new BillingRecordBatchSubmitter($this->ljStore(), $racing, $this->ljHeads(), new EventDispatcher(), $flow, $clock, null, 1000, 300, 60, 'global');

        self::assertSame(BatchSubmissionResult::STATE_SENT, $first->submitPending('89890001K')->state);
        self::assertInstanceOf(BatchSubmissionResult::class, $during);
        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $during->state);
        self::assertSame([], $other->requests);
        $clock->sleep(60);
        self::assertSame(BatchSubmissionResult::STATE_SENT, $second->submitPending('12345678Z')->state);
        self::assertNull($this->ljHeads()->getLastState(BillingRecordBatchSubmitter::GLOBAL_FLOW_CONTROL_KEY), 'The lock row is an empty head, never a chain');
    }

    /**
     * LJ-L3: rows without an AEAT environment (written before 1.6) are never waiting, claimed or listed.
     */
    public function testRowsWithoutAnEnvironmentAreNeverClaimedOnTheDatabase(): void
    {
        $this->ljChain(['L-1', 'L-2', 'L-3']);
        $store = $this->ljStore();
        $now   = new DateTimeImmutable('2026-11-11 10:00:00');
        $this->ljEntityManager()->getConnection()->executeStatement('UPDATE ' . $this->ljTable() . ' SET ' . DbalRowWriter::column($this->ljEntityManager(), BillingRecordEntry::class, 'environment') . ' = NULL WHERE ' . DbalRowWriter::column($this->ljEntityManager(), BillingRecordEntry::class, 'position') . ' IN (1, 3)');

        self::assertSame(1, $store->countPending('89890001K', $now, 300));
        self::assertSame([2], array_map(static fn (StoredBillingRecord $s): int => $s->position, $store->claimNext('89890001K', 1000, 'c', $now, 300)));
        self::assertSame(['pending', 'sending', 'pending'], $this->ljColumn('submissionStatus'));
        self::assertSame(1, $store->releaseClaim('89890001K', 'c'));
        $this->ljEntityManager()->getConnection()->executeStatement('UPDATE ' . $this->ljTable() . ' SET ' . DbalRowWriter::column($this->ljEntityManager(), BillingRecordEntry::class, 'environment') . ' = NULL');
        self::assertSame([], $store->issuersWithPendingRecords());
        self::assertSame([], $store->claimNext('89890001K', 1000, 'd', $now, 300));
    }

    /**
     * @param list<string> $numbers
     */
    private function ljChain(array $numbers, string $issuer = '89890001K'): void
    {
        foreach ($numbers as $number) {
            $result = $this->ljProcessor()->process(new BillingRecord(RecordType::Alta, $issuer, $number, '09-07-2026', 'F1', '21.00', '121.00', '', operationDescription: 'Audit LJ'));
            self::assertSame([], $result['errors']);
        }
    }

    /**
     * @return list<mixed>
     */
    private function ljColumn(string $field): array
    {
        return array_values(array_map(
            static fn (StoredBillingRecord $s): mixed => match ($field) {
                'submissionAttempts' => $s->submissionAttempts,
                'submissionFailures' => $s->submissionFailures,
                'submissionStatus'   => $s->submissionStatus,
                default              => $s->submissionError,
            },
            [...$this->ljStore()->findByIssuer('89890001K')],
        ));
    }

    private function ljTable(): string
    {
        return DbalRowWriter::table($this->ljEntityManager(), BillingRecordEntry::class);
    }
}
