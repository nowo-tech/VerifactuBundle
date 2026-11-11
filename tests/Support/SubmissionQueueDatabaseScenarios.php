<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Support;

use ArrayObject;
use Closure;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\VerifactuBundle\Client\AeatResponseParser;
use Nowo\VerifactuBundle\Doctrine\DbalRowWriter;
use Nowo\VerifactuBundle\Entity\BillingRecordEntry;
use Nowo\VerifactuBundle\Event\IssuerSubmissionPausedEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
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
     * counting keeps them; an answer resets them; a held record is failed or put back (circuit breaker).
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
        self::assertSame(0, $store->parkClaim('89890001K', 'nobody', StoredBillingRecord::STATUS_HELD));
        self::assertSame(1, $store->parkClaim('89890001K', 'c4', StoredBillingRecord::STATUS_HELD, 'AEAT SOAP fault: y', true));
        $suspects = $store->suspects('89890001k');
        self::assertSame([[3, 'held', 'AEAT SOAP fault: y', 2]], array_map(static fn (StoredBillingRecord $s): array => [$s->position, $s->submissionStatus, $s->submissionError, $s->submissionFailures], $suspects));
        self::assertSame(1, $store->countPending('89890001K', $now, 300), 'A suspect is not waiting');
        self::assertSame(['89890001K'], $store->issuersWithPendingRecords(), 'but its issuer is listed');
        self::assertSame(4, $store->newestWaitingPosition('89890001K', $now, 300));

        // A probe was answered: the suspect is confirmed alone, refused again: failed.
        self::assertSame(1, $store->confirmSuspects('89890001K'));
        self::assertSame(['accepted', 'accepted', 'confirming', 'pending'], $this->ljColumn('submissionStatus'));
        $confirm = $store->claimRecord('89890001K', 3, 'c5', $now, 300);
        self::assertSame([3, 'sending'], [$confirm?->position, $confirm?->submissionStatus]);
        self::assertNull($store->claimRecord('89890001K', 3, 'c6', $now, 300), 'Claimed once');
        self::assertNull($store->claimRecord('89890001K', 1, 'c6', $now, 300), 'An answered record is not claimable');
        self::assertSame([], $store->failClaim('89890001K', 'nobody', 'x', $now));
        self::assertSame([strtoupper((string) $alone[0]->record->hash)], $store->failClaim('89890001K', 'c5', 'AEAT SOAP fault: z', $now));
        self::assertSame(['accepted', 'accepted', 'failed', 'pending'], $this->ljColumn('submissionStatus'));
        self::assertSame([0, 0, 3, 1], $this->ljColumn('submissionFailures'));

        self::assertSame(1, $store->requeueFailed('89890001K'));
        self::assertSame(['accepted', 'accepted', 'pending', 'pending'], $this->ljColumn('submissionStatus'));
        self::assertSame([0, 0, 0, 1], $this->ljColumn('submissionFailures'));
        self::assertSame(0, $store->requeueFailed('89890001K'));

        // Failed by hand (--fail): pending, held or confirming only; requeueable.
        self::assertNull($store->failRecord('89890001K', 1, 'by hand', $now));
        self::assertSame(4, $store->failRecord('89890001K', 4, 'by hand', $now)?->position);
        self::assertSame(['accepted', 'accepted', 'pending', 'failed'], $this->ljColumn('submissionStatus'));
        self::assertSame('by hand', $this->ljColumn('submissionError')[3]);
        self::assertSame(1, $store->requeueFailed('89890001K'));
    }

    /**
     * LJD-L1: only the records the batch submitter failed are requeued; an AEAT `Incorrecto` stored as failed by
     * process(..., submitToAeat: true) stays failed. LJD-I1: an unconfirmed record is resolved after a check.
     */
    public function testRequeueOnlyTheSubmitterFailuresAndResolveUnconfirmedOnTheDatabase(): void
    {
        $this->ljChain(['R-1', 'R-2']);
        $store  = $this->ljStore();
        $now    = new DateTimeImmutable('2026-11-11 10:00:00');
        $stored = [...$store->findByIssuer('89890001K')];
        $store->markSubmitted('89890001K', (string) $stored[0]->record->hash, false);

        self::assertSame(0, $store->requeueFailed('89890001K'));
        self::assertSame(['failed', 'pending'], $this->ljColumn('submissionStatus'));

        $claimed = $store->claimNext('89890001K', 10, 'u', $now, 300);
        $store->recordOutcomes('89890001K', 'u', [new RecordSubmissionOutcome($claimed[0], RecordSubmissionStatus::Unconfirmed, null, 3000, 'Duplicado')], $now);
        self::assertFalse($store->resolveUnconfirmed('89890001K', 1, RecordSubmissionStatus::Accepted, 'CSV', $now));
        self::assertTrue($store->resolveUnconfirmed('89890001k', 2, RecordSubmissionStatus::AcceptedWithErrors, 'CSV-CHECKED', $now));
        self::assertSame(['failed', 'accepted_errors'], $this->ljColumn('submissionStatus'));
        self::assertSame('CSV-CHECKED', [...$store->findByIssuer('89890001K')][1]->submissionReference);
    }

    /**
     * LJD-M1: six persistent errors that are not caused by any record (an issuer-wide header Client fault, an AEAT
     * Server fault, HTTP 403 / 401 / 500 HTML / 404) with 60 healthy records: none is ever marked failed. The
     * issuer-wide Client fault pauses the issuer once.
     */
    public function testPersistentIssuerWideErrorsNeverFailRecordsOnTheDatabase(): void
    {
        $cases = [
            'Client 4104'  => static fn (): AeatBatchResponse => ScriptedBatchClient::fault(4104),
            'Server 4118'  => static fn (): AeatBatchResponse => ScriptedBatchClient::fault(4118, 'env:Server'),
            'HTTP 403'     => static fn (): AeatBatchResponse => (new AeatResponseParser())->parse('<html><body>403 Forbidden</body></html>', 403),
            'HTTP 401'     => static fn (): AeatBatchResponse => (new AeatResponseParser())->parse('', 401),
            'HTTP 500 web' => static fn (): AeatBatchResponse => (new AeatResponseParser())->parse('<html><body>Servicio no disponible</body></html>', 500),
            'HTTP 404'     => static fn (): AeatBatchResponse => (new AeatResponseParser())->parse('<html><body>Not found</body></html>', 404),
        ];
        $numbers = array_map(static fn (int $i): string => 'W-' . $i, range(1, 60));
        $n       = 0;
        foreach ($cases as $name => $error) {
            $number = 30000000 + $n++;
            $issuer = $number . 'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
            $this->ljChain($numbers, $issuer);
            $paused  = new ArrayObject();
            $stalled = new ArrayObject();
            $events  = new EventDispatcher();
            $events->addListener(VerifactuEvents::ISSUER_SUBMISSION_PAUSED, static function () use ($paused): void {
                $paused->append(true);
            });
            $events->addListener(VerifactuEvents::ISSUER_SUBMISSION_STALLED, static function () use ($stalled): void {
                $stalled->append(true);
            });
            $clock = new MockClock('2026-11-11 10:00:00');
            /** @var list<Closure> $script */
            $script    = array_fill(0, 60, static fn (array $records): AeatBatchResponse => $error());
            $client    = new ScriptedBatchClient(...$script);
            $submitter = new BillingRecordBatchSubmitter($this->ljStore(), $client, $this->ljHeads(), $events, new CacheFlowControl(new ArrayAdapter()), $clock, null, 1000, 300, 60, 'issuer', null, 5);

            for ($i = 0; $i < 50; ++$i) {
                $submitter->submitPending($issuer);
                $clock->sleep(61);
            }

            $statuses = array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, [...$this->ljStore()->findByIssuer($issuer)]);
            self::assertSame(array_fill(0, 60, 'pending'), $statuses, $name);
            // 4104 is a header code: paused at once, one alert; the others: one stalled alert.
            self::assertCount($name === 'Client 4104' ? 1 : 0, $paused, $name);
            self::assertCount($name === 'Client 4104' ? 0 : 1, $stalled, $name);
            self::assertCount($name === 'Client 4104' ? 1 : 50, $client->requests, $name);
        }
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
     * LJD2-M1: runs of consecutive bad records (an unknown code: probes; a record code: failed directly) and an
     * issuer-wide unknown error (the suspects and the newest record refused: paused, suspects kept, then confirmed and
     * accepted once the AEAT answers again) on the database.
     */
    public function testTheCircuitBreakerOnTheDatabase(): void
    {
        $cases = [
            '2 in a row, unknown' => [[7, 8], 4199],
            '3 in a row, unknown' => [[7, 8, 9], 4199],
            '3 in a row, record'  => [[7, 8, 9], 4102],
        ];
        $numbers = array_map(static fn (int $i): string => 'C-' . $i, range(1, 20));
        $n       = 0;
        foreach ($cases as $name => [$bad, $code]) {
            $number = 40000000 + $n++;
            $issuer = $number . 'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
            $this->ljChain($numbers, $issuer);
            $badNumbers = array_map(static fn (int $i): string => 'C-' . $i, $bad);
            /** @var list<Closure> $script */
            $script = array_fill(0, 200, static fn (array $records): AeatBatchResponse => array_intersect($badNumbers, array_map(static fn (BillingRecord $r): string => $r->invoiceSeriesNumber, $records)) !== []
                ? ScriptedBatchClient::fault($code)
                : ScriptedBatchClient::allAccepted($records));
            $paused = new ArrayObject();
            $events = new EventDispatcher();
            $events->addListener(VerifactuEvents::ISSUER_SUBMISSION_PAUSED, static function () use ($paused): void {
                $paused->append(true);
            });
            $clock     = new MockClock('2026-11-11 10:00:00');
            $submitter = new BillingRecordBatchSubmitter($this->ljStore(), new ScriptedBatchClient(...$script), $this->ljHeads(), $events, new CacheFlowControl(new ArrayAdapter()), $clock, null, 1000, 300, 60, 'issuer', null, 2);
            for ($i = 0; $i < 100 && $submitter->submitPending($issuer)->state !== BatchSubmissionResult::STATE_IDLE; ++$i) {
                $clock->sleep(61);
            }

            $statuses = array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, [...$this->ljStore()->findByIssuer($issuer)]);
            self::assertSame(array_map(static fn (int $i): string => in_array($i, $bad, true) ? 'failed' : 'accepted', range(1, 20)), $statuses, $name);
            self::assertCount(0, $paused, $name);
        }

        // Issuer-wide, unknown code.
        $number = 40000000 + $n;
        $issuer = $number . 'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
        $this->ljChain($numbers, $issuer);
        $aeat = new ArrayObject(['broken' => true]);
        /** @var list<Closure> $script */
        $script = array_fill(0, 200, static fn (array $records): AeatBatchResponse => $aeat['broken'] === true ? ScriptedBatchClient::fault(4199) : ScriptedBatchClient::allAccepted($records));
        $pauses = [];
        $events = new EventDispatcher();
        $events->addListener(VerifactuEvents::ISSUER_SUBMISSION_PAUSED, static function (IssuerSubmissionPausedEvent $event) use (&$pauses): void {
            $pauses[] = $event;
        });
        $clock     = new MockClock('2026-11-11 10:00:00');
        $submitter = new BillingRecordBatchSubmitter($this->ljStore(), new ScriptedBatchClient(...$script), $this->ljHeads(), $events, new CacheFlowControl(new ArrayAdapter()), $clock, null, 1000, 300, 60, 'issuer', null, 2);
        for ($i = 0; $i < 40; ++$i) {
            $submitter->submitPending($issuer);
            $clock->sleep(61);
        }
        self::assertSame([[1, 2, 3]], array_map(static fn (IssuerSubmissionPausedEvent $e): array => $e->heldPositions, $pauses));
        $statuses = array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, [...$this->ljStore()->findByIssuer($issuer)]);
        self::assertSame(['held', 'held', 'held', ...array_fill(0, 17, 'pending')], $statuses);

        $aeat['broken'] = false;
        $clock->sleep(3600);
        for ($i = 0; $i < 20 && $submitter->submitPending($issuer)->state !== BatchSubmissionResult::STATE_IDLE; ++$i) {
            $clock->sleep(61);
        }
        $statuses = array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, [...$this->ljStore()->findByIssuer($issuer)]);
        self::assertSame(array_fill(0, 20, 'accepted'), $statuses, 'Nothing failed');
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
