<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use ArrayObject;
use Closure;
use DateTimeImmutable;
use LogicException;
use Nowo\VerifactuBundle\Client\AeatResponseParser;
use Nowo\VerifactuBundle\Event\IssuerSubmissionPausedEvent;
use Nowo\VerifactuBundle\Event\RecordSubmissionFailedEvent;
use Nowo\VerifactuBundle\Event\RecordSubmittedEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\AeatRecordResponse;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\RecordType;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Provider\AeatEnvironmentProviderInterface;
use Nowo\VerifactuBundle\Repository\HashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Repository\SubmissionQueueInterface;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Submission\CacheFlowControl;
use Nowo\VerifactuBundle\Submission\DuplicateConfirmationInterface;
use Nowo\VerifactuBundle\Tests\Support\RecordingDuplicateConfirmation;
use Nowo\VerifactuBundle\Tests\Support\RecordingLockableHashChainRepository;
use Nowo\VerifactuBundle\Tests\Support\RecordingLogger;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use stdClass;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_slice;
use function count;
use function in_array;
use function sprintf;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordBatchSubmitterTest extends TestCase
{
    private InMemoryHashChainRepository $heads;

    private InMemoryBillingRecordStore $store;

    private BillingRecordProcessor $processor;

    private MockClock $clock;

    /** The flow control of the last submitter() built with the default one. */
    private CacheFlowControl $flow;

    private EventDispatcher $dispatcher;

    /** @var list<RecordSubmittedEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        $this->heads      = new InMemoryHashChainRepository();
        $this->store      = new InMemoryBillingRecordStore();
        $this->processor  = TestProcessorFactory::create($this->heads, $this->store);
        $this->clock      = new MockClock('2026-11-11 10:00:00');
        $this->dispatcher = new EventDispatcher();
        $this->dispatcher->addListener(VerifactuEvents::AFTER_RECORD_SUBMISSION, function (RecordSubmittedEvent $event): void {
            $this->events[] = $event;
        });
    }

    public function testBatchesFollowTheChainOrderAndTheBatchSize(): void
    {
        $this->chain(7);
        $client    = new ScriptedBatchClient();
        $submitter = $this->submitter($client, batchSize: 3, flowControl: false);

        $first = $submitter->submitPending('89890001k');
        self::assertSame(BatchSubmissionResult::STATE_SENT, $first->state);
        self::assertSame(4, $first->remaining);
        self::assertSame(1, $submitter->submitPending('89890001K')->remaining);
        $last = $submitter->submitPending('89890001K');
        self::assertSame(0, $last->remaining);
        self::assertSame(BatchSubmissionResult::STATE_IDLE, $submitter->submitPending('89890001K')->state);

        self::assertSame([3, 3, 1], array_map('count', $client->requests));
        $sent = array_map(static fn (BillingRecord $record): string => $record->invoiceSeriesNumber, array_merge(...$client->requests));
        self::assertSame(['F-1', 'F-2', 'F-3', 'F-4', 'F-5', 'F-6', 'F-7'], $sent);
        // The chain links of what was sent are the links of the stored chain: nothing re-chained or reordered.
        $stored = iterator_to_array($this->store->findByIssuer('89890001K'));
        self::assertSame(array_map(static fn (StoredBillingRecord $s): ?string => $s->record->hash, $stored), array_map(static fn (BillingRecord $r): ?string => $r->hash, array_merge(...$client->requests)));
        self::assertSame(array_fill(0, 7, StoredBillingRecord::STATUS_ACCEPTED), array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, $stored));
        self::assertSame('CSV-3', $stored[0]->submissionReference);
        self::assertCount(7, $this->events);
    }

    public function testPerRecordAnswersOfAPartiallyCorrectSubmission(): void
    {
        $this->chain(4);
        $client = new ScriptedBatchClient(static fn (array $records): AeatBatchResponse => new AeatBatchResponse(
            AeatBatchResponse::ENVIO_PARTIALLY_CORRECT,
            [
                ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Accepted),
                ScriptedBatchClient::line($records[1], RecordSubmissionStatus::AcceptedWithErrors, 2004, 'Valor del campo con errores admisibles.'),
                ScriptedBatchClient::line($records[2], RecordSubmissionStatus::Rejected, 1100, 'Valor o tipo incorrecto del campo.'),
                // no line for the fourth record
            ],
            'CSV-PARTIAL',
            45,
        ));

        $result = $this->submitter($client)->submitPending('89890001K');

        self::assertSame(BatchSubmissionResult::STATE_SENT, $result->state);
        self::assertSame([1, 1, 1, 1], [$result->count(RecordSubmissionStatus::Accepted), $result->count(RecordSubmissionStatus::AcceptedWithErrors), $result->count(RecordSubmissionStatus::Rejected), $result->count(null)]);
        self::assertEquals(new DateTimeImmutable('2026-11-11 10:00:45'), $result->nextSubmissionAt);
        self::assertSame(1, $result->remaining, 'The record without an answer is waiting again');

        $stored = iterator_to_array($this->store->findByIssuer('89890001K'));
        self::assertSame([StoredBillingRecord::STATUS_ACCEPTED, StoredBillingRecord::STATUS_ACCEPTED_WITH_ERRORS, StoredBillingRecord::STATUS_REJECTED, StoredBillingRecord::STATUS_PENDING], array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, $stored));
        self::assertSame(['CSV-PARTIAL', 'CSV-PARTIAL', null, null], array_map(static fn (StoredBillingRecord $s): ?string => $s->submissionReference, $stored));
        self::assertSame([null, 2004, 1100, null], array_map(static fn (StoredBillingRecord $s): ?int => $s->submissionErrorCode, $stored));
        self::assertSame('Valor o tipo incorrecto del campo.', $stored[2]->submissionError);
        // Events only for the answered records, after their answer is stored.
        self::assertSame([RecordSubmissionStatus::Accepted, RecordSubmissionStatus::AcceptedWithErrors, RecordSubmissionStatus::Rejected], array_map(static fn (RecordSubmittedEvent $e): ?RecordSubmissionStatus => $e->outcome->status, $this->events));
        // The rejected record stays in the chain: the head still points at the last generated record.
        self::assertSame($stored[3]->record->hash, $this->heads->getLastState('89890001K')?->hash);
    }

    public function testACorrectSubmissionWithoutLinesAcceptsEveryRecord(): void
    {
        $this->chain(2);
        $client = new ScriptedBatchClient(static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_CORRECT, [], 'CSV-ALL', 60));

        $result = $this->submitter($client)->submitPending('89890001K');

        self::assertSame(2, $result->count(RecordSubmissionStatus::Accepted));
        self::assertSame('CSV-ALL', iterator_to_array($this->store->findByIssuer('89890001K'))[1]->submissionReference);
    }

    public function testASubmissionWithoutAnswerIsRetriedIdempotently(): void
    {
        $this->chain(2);
        $client = new ScriptedBatchClient(
            static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('Operation timed out'),
            // The first request reached the AEAT after all: the retry gets "duplicate" for the first record.
            static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_PARTIALLY_CORRECT, [
                ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Rejected, 3000, 'Registro de facturación duplicado.', 'Correcta'),
                ScriptedBatchClient::line($records[1], RecordSubmissionStatus::Accepted),
            ], 'CSV-RETRY', 60),
        );
        $submitter = $this->submitter($client, flowControl: false);

        $failed = $submitter->submitPending('89890001K');
        self::assertTrue($failed->isRetryable());
        self::assertSame(2, $failed->remaining);
        self::assertSame([], $this->events);
        $stored = iterator_to_array($this->store->findByIssuer('89890001K'));
        self::assertSame([StoredBillingRecord::STATUS_PENDING, StoredBillingRecord::STATUS_PENDING], array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, $stored));
        self::assertSame('Operation timed out', $stored[0]->submissionError);

        $retry = $submitter->submitPending('89890001K');
        // The duplicate has no Huella: without a confirmation it is never reported as accepted (LJ-M2).
        self::assertSame([1, 1], [$retry->count(RecordSubmissionStatus::Accepted), $retry->count(RecordSubmissionStatus::Unconfirmed)]);
        self::assertSame([$client->requests[0][0]->xml, $client->requests[0][1]->xml], [$client->requests[1][0]->xml, $client->requests[1][1]->xml], 'The same stored XML is sent again');
        $stored = iterator_to_array($this->store->findByIssuer('89890001K'));
        self::assertSame([2, 2], array_map(static fn (StoredBillingRecord $s): int => $s->submissionAttempts, $stored));
        self::assertSame(3000, $stored[0]->submissionErrorCode);
    }

    public function testADuplicateOnTheFirstAttemptIsARealConflict(): void
    {
        $this->chain(1);
        $client = new ScriptedBatchClient(static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_INCORRECT, [
            ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Rejected, 3000, 'Registro de facturación duplicado.', 'Correcta'),
        ], null, 60));

        $result = $this->submitter($client)->submitPending('89890001K');

        self::assertSame(1, $result->count(RecordSubmissionStatus::Rejected));
        self::assertSame(StoredBillingRecord::STATUS_REJECTED, iterator_to_array($this->store->findByIssuer('89890001K'))[0]->submissionStatus);
    }

    public function testADuplicateOfAnAnnulledRecordIsUnconfirmedUnlessConfirmed(): void
    {
        $answers = static fn (): array => [
            static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('reset by peer', 0, '', '', true),
            static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_INCORRECT, [
                ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Rejected, 3000, 'Registro de facturación duplicado.', 'Anulada'),
            ], null, 60),
        ];
        $this->chain(1);
        $submitter = $this->submitter(new ScriptedBatchClient(...$answers()), flowControl: false);
        $submitter->submitPending('89890001K');
        self::assertSame(1, $submitter->submitPending('89890001K')->count(RecordSubmissionStatus::Unconfirmed));

        // Confirmed (same Huella): an alta registered and annulled later was registered (LJ-L2).
        $this->chain(1, 2);
        $submitter = $this->submitter(new ScriptedBatchClient(...$answers()), flowControl: false, confirmation: $this->confirmation(true));
        $submitter->submitPending('89890001K');
        self::assertSame(1, $submitter->submitPending('89890001K')->count(RecordSubmissionStatus::Accepted));
    }

    public function testTheAeatWaitTimeIsHonoured(): void
    {
        $this->chain(1);
        $client    = new ScriptedBatchClient();
        $submitter = $this->submitter($client, batchSize: 3);
        $submitter->submitPending('89890001K');

        $this->chain(2, 2);
        $throttled = $submitter->submitPending('89890001K');
        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $throttled->state);
        self::assertEquals(new DateTimeImmutable('2026-11-11 10:01:00'), $throttled->nextSubmissionAt);
        self::assertSame(2, $throttled->remaining);
        self::assertCount(1, $client->requests);

        $this->chain(1, 5);
        $this->clock->sleep(59);
        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $submitter->submitPending('89890001K')->state);
        $this->clock->sleep(1);
        self::assertSame(BatchSubmissionResult::STATE_SENT, $submitter->submitPending('89890001K')->state);
    }

    /**
     * LJ-M1 (Orden HAC/1177/2024 art. 16.2 c): the wait is skipped only for the record-design limit, 1000 records, not
     * for a full batch of a smaller `batch_size`.
     */
    public function testOnlyTheRecordDesignLimitSkipsTheWait(): void
    {
        $client    = new ScriptedBatchClient();
        $submitter = $this->submitter($client, batchSize: 10);
        $this->fill(10);
        self::assertSame(BatchSubmissionResult::STATE_SENT, $submitter->submitPending('89890001K')->state);

        // 10 waiting = a full batch of batch_size 10, 5 s after the previous request: it waits (it was sent before).
        $this->fill(10, 11);
        $this->clock->sleep(5);
        $smallBatch = $submitter->submitPending('89890001K');
        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $smallBatch->state);
        // Even 1000 waiting: a request of 10 records is not the record-design limit.
        $this->fill(990, 21);
        $manyWaiting = $submitter->submitPending('89890001K');
        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $manyWaiting->state);
        self::assertSame(1000, $manyWaiting->remaining);
        self::assertSame([10], array_map('count', $client->requests));

        // batch_size 1000 and 1000 waiting: the 1000-record request may go inside the wait.
        $full = $this->submitter($client, batchSize: 1000, flowControl: $this->flow);
        $sent = $full->submitPending('89890001K');
        self::assertSame(BatchSubmissionResult::STATE_SENT, $sent->state);
        self::assertSame([10, 1000], array_map('count', $client->requests));
        self::assertSame(BatchSubmissionResult::STATE_IDLE, $full->submitPending('89890001K')->state);
    }

    /**
     * LJ-M1: 1000 records wait but the claim stops short of 1000 (here an environment change): not a full request, so
     * it waits and nothing is claimed, sent or counted.
     */
    public function testAClaimShortOfTheRecordDesignLimitWaits(): void
    {
        $client    = new ScriptedBatchClient();
        $submitter = $this->submitter($client, batchSize: 1000);
        $this->fill(1);
        $submitter->submitPending('89890001K');

        $this->fill(600, 2);
        $this->fill(400, 602, AeatEnvironment::Production);
        $this->clock->sleep(5);
        $result = $submitter->submitPending('89890001K');

        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $result->state);
        self::assertCount(1, $client->requests);
        self::assertSame(1000, $this->store->countPending('89890001K', $this->clock->now(), 300));
        self::assertFalse($this->store->hasSubmissionInFlight('89890001K', $this->clock->now(), 300));
        self::assertSame([0], array_values(array_unique(array_map(static fn (StoredBillingRecord $s): int => $s->submissionAttempts, array_slice(iterator_to_array($this->store->findByIssuer('89890001K')), 1)))));
    }

    public function testAGlobalFlowControlScopeSharesTheWaitBetweenIssuers(): void
    {
        $this->chain(1);
        $this->chain(1, 1, '12345678Z');
        $flow   = new CacheFlowControl(new ArrayAdapter());
        $client = new ScriptedBatchClient();

        $perIssuer = $this->submitter($client, flowControl: $flow);
        $perIssuer->submitPending('89890001K');
        self::assertSame(BatchSubmissionResult::STATE_SENT, $perIssuer->submitPending('12345678Z')->state);

        $this->chain(1, 2);
        $this->chain(1, 2, '12345678Z');
        $global = $this->submitter($client, flowControl: new CacheFlowControl(new ArrayAdapter()), scope: 'global');
        $global->submitPending('89890001K');
        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $global->submitPending('12345678Z')->state);
    }

    public function testOneBatchInFlightPerIssuerAndAStaleClaimIsTakenOver(): void
    {
        $this->chain(2);
        // Another process claimed the records and died before storing the answer.
        $this->store->claimNext('89890001K', 1000, 'dead-process', new DateTimeImmutable('2026-11-11 10:00:00'), 300);
        $client    = new ScriptedBatchClient();
        $submitter = $this->submitter($client, flowControl: false);

        self::assertSame(BatchSubmissionResult::STATE_IN_FLIGHT, $submitter->submitPending('89890001K')->state);
        self::assertSame([], $client->requests);

        $this->clock->sleep(301);
        $result = $submitter->submitPending('89890001K');
        self::assertSame(2, $result->count(RecordSubmissionStatus::Accepted));
        // The dead process cannot overwrite the answer any more.
        self::assertSame([], $this->store->recordOutcomes('89890001K', 'dead-process', $result->outcomes, new DateTimeImmutable()));
    }

    public function testABatchNeverMixesEnvironmentsNorTwoRecordsOfOneInvoice(): void
    {
        $environment = new class implements AeatEnvironmentProviderInterface {
            public AeatEnvironment $value = AeatEnvironment::Sandbox;

            public function getEnvironment(): AeatEnvironment
            {
                return $this->value;
            }
        };
        $this->processor = TestProcessorFactory::create($this->heads, $this->store, null, $environment);
        $this->chain(2);
        $this->heads->reset('89890001K');
        $environment->value = AeatEnvironment::Production;
        $this->chain(1, 3);
        // A correction of F-3 (Subsanacion S) must go in a later submission than F-3 itself (Servicios web §9.1.2).
        self::assertSame([], $this->processor->process(TestProcessorFactory::record('F-3', correction: 'S'))['errors']);

        $client    = new ScriptedBatchClient();
        $submitter = $this->submitter($client, flowControl: false);
        while ($submitter->submitPending('89890001K')->state === BatchSubmissionResult::STATE_SENT) {
        }

        self::assertSame([['F-1', 'F-2'], ['F-3'], ['F-3']], array_map(static fn (array $records): array => array_map(static fn (BillingRecord $r): string => $r->invoiceSeriesNumber, $records), $client->requests));
        self::assertSame([AeatEnvironment::Sandbox, AeatEnvironment::Production, AeatEnvironment::Production], array_map(static fn (array $records): ?AeatEnvironment => $records[0]->environment, $client->requests));
        self::assertStringContainsString('<Subsanacion>S</Subsanacion>', (string) $client->requests[2][0]->xml);
    }

    public function testAListenerFailureNeverChangesTheStoredAnswer(): void
    {
        $this->chain(1);
        $this->dispatcher->addListener(VerifactuEvents::AFTER_RECORD_SUBMISSION, static function (): never {
            throw new RuntimeException('listener down');
        });

        $result = $this->submitter(new ScriptedBatchClient())->submitPending('89890001K');

        self::assertCount(1, $result->eventErrors);
        self::assertSame(StoredBillingRecord::STATUS_ACCEPTED, iterator_to_array($this->store->findByIssuer('89890001K'))[0]->submissionStatus);
    }

    public function testAClientExceptionPutsTheBatchBackAndPropagates(): void
    {
        $this->chain(1);
        $client = new ScriptedBatchClient(static fn (array $records): never => throw new RuntimeException('no certificate'));

        try {
            $this->submitter($client)->submitPending('89890001K');
            self::fail('The exception was swallowed.');
        } catch (RuntimeException $exception) {
            self::assertSame('no certificate', $exception->getMessage());
        }
        self::assertSame(StoredBillingRecord::STATUS_PENDING, iterator_to_array($this->store->findByIssuer('89890001K'))[0]->submissionStatus);
    }

    public function testTheClaimRunsInsideTheIssuerChainLock(): void
    {
        $this->chain(1);
        $lockable  = new RecordingLockableHashChainRepository();
        $submitter = new BillingRecordBatchSubmitter($this->store, new ScriptedBatchClient(), $lockable, $this->dispatcher, null, $this->clock);

        $submitter->submitPending(' 89890001k ');

        self::assertSame(['lock:89890001K', 'unlock:89890001K'], $lockable->calls);
    }

    public function testAQueueThatClaimsNothingIsIdle(): void
    {
        $queue = $this->createMock(SubmissionQueueInterface::class);
        $queue->method('countPending')->willReturn(1);
        $queue->method('claimNext')->willReturn([]);

        self::assertSame(BatchSubmissionResult::STATE_IDLE, (new BillingRecordBatchSubmitter($queue, new ScriptedBatchClient(), $this->heads, $this->dispatcher))->submitPending('89890001K')->state);
    }

    public function testTheInMemoryQueueReleasesOnlyItsOwnClaim(): void
    {
        $this->chain(2);
        $now = new DateTimeImmutable('2026-11-11 10:00:00');
        $this->store->claimNext('89890001K', 1, 'mine', $now, 300);
        $this->store->claimNext('89890001K', 1, 'theirs', $now, 300);

        self::assertSame(1, $this->store->releaseClaim('89890001K', 'mine', 'timeout'));
        self::assertSame([StoredBillingRecord::STATUS_PENDING, StoredBillingRecord::STATUS_SENDING], array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, iterator_to_array($this->store->findByIssuer('89890001K'))));
        self::assertSame(0, $this->store->releaseClaim('89890001K', 'mine', 'again'));
    }

    public function testItRefusesToRunInsideATransaction(): void
    {
        $queue = $this->createMock(SubmissionQueueInterface::class);
        $queue->method('isInsideTransaction')->willReturn(true);

        $this->expectException(LogicException::class);
        (new BillingRecordBatchSubmitter($queue, new ScriptedBatchClient(), $this->heads, $this->dispatcher))->submitPending('89890001K');
    }

    public function testIssuersWithWaitingRecords(): void
    {
        $this->chain(1);
        $this->chain(1, 1, '12345678Z');

        self::assertSame(['12345678Z', '89890001K'], $this->submitter(new ScriptedBatchClient())->issuersWithPendingRecords());
    }

    /**
     * LJ-M2: an attempt is counted when the request is transmitted, not when the batch is claimed. The auditor's case:
     * a claim dies before sending, the takeover is the FIRST transmission, and a duplicate answer then means another
     * record holds the invoice id: rejected, never accepted.
     */
    public function testAnAttemptIsCountedOnlyWhenTheRequestIsTransmitted(): void
    {
        $this->chain(1);
        $now = $this->clock->now();

        // Nothing leaves the process: no attempt.
        try {
            $this->submitter(new ScriptedBatchClient(ScriptedBatchClient::NOT_SENT), flowControl: false)->submitPending('89890001K');
            self::fail('The client exception was swallowed.');
        } catch (RuntimeException) {
        }
        self::assertSame(0, iterator_to_array($this->store->findByIssuer('89890001K'))[0]->submissionAttempts);

        // A process claims the record and dies before sending: no attempt either.
        self::assertCount(1, $this->store->claimNext('89890001K', 1000, 'died-before-sending', $now, 300));
        self::assertSame(0, iterator_to_array($this->store->findByIssuer('89890001K'))[0]->submissionAttempts);
        $this->clock->sleep(301);

        $client = new ScriptedBatchClient(static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_INCORRECT, [
            ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Rejected, 3000, 'Registro de facturación duplicado.', 'Correcta'),
        ], null, 60));
        $result = $this->submitter($client, flowControl: false)->submitPending('89890001K');

        self::assertSame(1, $result->count(RecordSubmissionStatus::Rejected));
        $stored = iterator_to_array($this->store->findByIssuer('89890001K'))[0];
        self::assertSame([StoredBillingRecord::STATUS_REJECTED, 1, null], [$stored->submissionStatus, $stored->submissionAttempts, $stored->submissionReference]);
        self::assertStringContainsString('IdPeticionRegistroDuplicado IDPET-1, EstadoRegistroDuplicado Correcta', (string) $stored->submissionError);
    }

    /**
     * LJ-M2: a duplicate for a record transmitted before is `unconfirmed` without a confirmation: no CSV, never
     * reported as accepted, never sent again; one event with that status.
     */
    public function testADuplicateOfARecordSentBeforeIsUnconfirmedWithoutConfirmation(): void
    {
        $this->chain(1);
        $client = new ScriptedBatchClient(
            static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('Operation timed out', 0, '', '', true),
            static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_INCORRECT, [
                ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Rejected, 3000, 'Registro de facturación duplicado.', 'Correcta'),
            ], 'CSV-DUP', 60),
        );
        $submitter = $this->submitter($client, flowControl: false);
        $submitter->submitPending('89890001K');
        $result = $submitter->submitPending('89890001K');

        self::assertSame(1, $result->count(RecordSubmissionStatus::Unconfirmed));
        self::assertSame(0, $result->count(RecordSubmissionStatus::Accepted));
        $stored = iterator_to_array($this->store->findByIssuer('89890001K'))[0];
        self::assertSame([StoredBillingRecord::STATUS_UNCONFIRMED, 2, null, 3000], [$stored->submissionStatus, $stored->submissionAttempts, $stored->submissionReference, $stored->submissionErrorCode]);
        self::assertSame([RecordSubmissionStatus::Unconfirmed], array_map(static fn (RecordSubmittedEvent $e): ?RecordSubmissionStatus => $e->outcome->status, $this->events));
        self::assertSame(BatchSubmissionResult::STATE_IDLE, $submitter->submitPending('89890001K')->state, 'Not sent again');
        self::assertCount(2, $client->requests);
    }

    /**
     * LJ-M2 + LJ-L2: with a DuplicateConfirmationInterface (e.g. an AEAT query comparing the Huella) the duplicate takes
     * the AEAT state by record type; another record's Huella is a rejection; a failing confirmation stays unconfirmed.
     */
    public function testAConfirmedDuplicateTakesTheAeatStateByRecordType(): void
    {
        $cases = [
            // [confirmation, record type, EstadoRegistroDuplicado, expected]
            [true, RecordType::Alta, 'Correcta', StoredBillingRecord::STATUS_ACCEPTED],
            [true, RecordType::Alta, 'AceptadaConErrores', StoredBillingRecord::STATUS_ACCEPTED_WITH_ERRORS],
            [true, RecordType::Alta, 'Anulada', StoredBillingRecord::STATUS_ACCEPTED],
            [true, RecordType::Anulacion, 'Anulada', StoredBillingRecord::STATUS_ACCEPTED],
            [true, RecordType::Anulacion, 'Correcta', StoredBillingRecord::STATUS_UNCONFIRMED],
            [false, RecordType::Alta, 'Correcta', StoredBillingRecord::STATUS_REJECTED],
            [null, RecordType::Alta, 'Correcta', StoredBillingRecord::STATUS_UNCONFIRMED],
            ['throws', RecordType::Alta, 'Correcta', StoredBillingRecord::STATUS_UNCONFIRMED],
        ];
        foreach ($cases as $i => [$same, $type, $state, $expected]) {
            $issuer = self::nif($i);
            $this->store->append(new BillingRecord($type, $issuer, 'D-' . $i, '09-07-2026', 'F1', '21.00', '121.00', '', hash: strtoupper(hash('sha256', 'dup' . $i)), xml: '<x/>', environment: AeatEnvironment::Sandbox));
            $client = new ScriptedBatchClient(
                static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('timeout', 0, '', '', true),
                static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_INCORRECT, [
                    ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Rejected, 3000, 'Duplicado', $state),
                ], 'CSV-' . $i, 60),
            );
            $confirmation = $this->confirmation($same === 'throws' ? null : $same, $same === 'throws');
            $submitter    = $this->submitter($client, flowControl: false, confirmation: $confirmation);
            $submitter->submitPending($issuer);
            $submitter->submitPending($issuer);

            $stored = iterator_to_array($this->store->findByIssuer($issuer))[0];
            self::assertSame($expected, $stored->submissionStatus, sprintf('case %d', $i));
            self::assertSame(in_array($expected, [StoredBillingRecord::STATUS_ACCEPTED, StoredBillingRecord::STATUS_ACCEPTED_WITH_ERRORS], true) ? 'CSV-' . $i : null, $stored->submissionReference);
            self::assertSame(['D-' . $i . '/' . $state], $confirmation->asked);
        }
    }

    /**
     * LJ-M3: a Client fault for a batch is not blamed on every record forever. The batch is halved until the record at
     * fault goes alone; after `max_attempts` refusals on its own it is held, the next record goes alone and, as that
     * probe is answered, the held record is marked failed (event after the commit). It can be requeued after the fix.
     */
    public function testASoapFaultSplitsTheBatchAndFailsOnlyTheRecordAtFault(): void
    {
        $this->chain(8);
        $badHash      = iterator_to_array($this->store->findByIssuer('89890001K'))[5]->record->hash;
        $aeat         = new stdClass();
        $aeat->broken = true;
        $answer       = static function (array $records) use ($aeat, $badHash): AeatBatchResponse {
            foreach ($records as $record) {
                if ($aeat->broken === true && $record->hash === $badHash) {
                    return ScriptedBatchClient::fault();
                }
            }

            return ScriptedBatchClient::allAccepted($records);
        };
        $failedEvents = [];
        $this->dispatcher->addListener(VerifactuEvents::RECORD_SUBMISSION_FAILED, static function (RecordSubmissionFailedEvent $event) use (&$failedEvents): void {
            $failedEvents[] = $event;
        });
        $client    = new ScriptedBatchClient(...array_fill(0, 30, $answer));
        $logger    = new RecordingLogger();
        $submitter = $this->submitter($client, batchSize: 8, flowControl: false, maxAttempts: 2, logger: $logger);
        self::assertSame(5, $submitter->failureLimit(), '3 halvings (8 → 4 → 2 → 1) + 2 refusals alone');

        $results = [];
        for ($i = 0; $i < 30 && ($result = $submitter->submitPending('89890001K'))->state !== BatchSubmissionResult::STATE_IDLE; ++$i) {
            $results[] = $result;
        }

        // ... 1 (F-6 alone, held), 1 (F-7 alone: the probe, answered), 1 (F-8).
        self::assertSame([8, 4, 4, 2, 1, 1, 1, 1, 1], array_map('count', $client->requests));
        self::assertSame(['accepted', 'accepted', 'accepted', 'accepted', 'accepted', 'failed', 'accepted', 'accepted'], $this->statuses());
        $failed = iterator_to_array($this->store->findByIssuer('89890001K'))[5];
        self::assertSame([5, 'AEAT SOAP fault: Codigo[4102].Error de prueba.'], [$failed->submissionFailures, $failed->submissionError]);
        self::assertCount(1, $failedEvents);
        self::assertSame(['89890001K', 6, $badHash], [$failedEvents[0]->issuerNif, $failedEvents[0]->position, $failedEvents[0]->hash]);
        $marking = array_values(array_filter($results, static fn (BatchSubmissionResult $r): bool => $r->failed !== []));
        self::assertCount(1, $marking);
        self::assertSame([BatchSubmissionResult::STATE_SENT, 1, 6], [$marking[0]->state, $marking[0]->count(RecordSubmissionStatus::Accepted), $marking[0]->failed[0]->position]);
        self::assertSame(0, array_sum(array_map(static fn (StoredBillingRecord $s): int => $s->submissionFailures, array_filter(iterator_to_array($this->store->findByIssuer('89890001K')), static fn (StoredBillingRecord $s): bool => $s->submissionStatus === 'accepted'))), 'An answer resets the failures');
        self::assertContains('error', array_column($logger->records, 'level'));
        self::assertSame([6], array_map(static fn (StoredBillingRecord $s): int => $s->position, $submitter->recordsToReview('89890001K')));

        // Fixed: requeued and sent.
        $aeat->broken = false;
        self::assertSame(1, $submitter->requeueFailed(' 89890001k '));
        self::assertSame(1, $submitter->submitPending('89890001K')->count(RecordSubmissionStatus::Accepted));
        self::assertSame(array_fill(0, 8, 'accepted'), $this->statuses());
        self::assertSame(0, $submitter->requeueFailed('89890001K'));
    }

    /**
     * LJD-M1: an issuer-wide Client fault (a header error such as Codigo[4104]) refuses every request whatever the
     * record. The probe is refused as well: no record is failed, every record is back to pending without failures,
     * the issuer is paused (one alert) and, once the cause is fixed, everything goes in one request.
     */
    public function testAnIssuerWideClientFaultPausesTheIssuerInsteadOfFailingRecords(): void
    {
        $this->fill(60);
        $aeat   = new ArrayObject(['broken' => true]);
        $answer = static fn (array $records): AeatBatchResponse => $aeat['broken'] === true ? ScriptedBatchClient::fault(4104) : ScriptedBatchClient::allAccepted($records);
        $paused = [];
        $failed = [];
        $this->dispatcher->addListener(VerifactuEvents::ISSUER_SUBMISSION_PAUSED, static function (IssuerSubmissionPausedEvent $event) use (&$paused): void {
            $paused[] = $event;
        });
        $this->dispatcher->addListener(VerifactuEvents::RECORD_SUBMISSION_FAILED, static function () use (&$failed): void {
            $failed[] = true;
        });
        $client    = new ScriptedBatchClient(...array_fill(0, 200, $answer));
        $submitter = $this->submitter($client, maxAttempts: 5);

        $states = $this->drive($submitter, 40);

        self::assertContains(BatchSubmissionResult::STATE_PAUSED, $states);
        self::assertSame(array_fill(0, 60, 'pending'), $this->statuses());
        self::assertCount(0, $failed);
        self::assertCount(1, $paused, 'One alert per pause');
        self::assertSame(['89890001K', 4104], [$paused[0]->issuerNif, $paused[0]->faultErrorCode]);
        self::assertSame([0], array_values(array_unique(array_map(static fn (StoredBillingRecord $s): int => $s->submissionFailures, iterator_to_array($this->store->findByIssuer('89890001K'))))));
        self::assertSame([], $submitter->recordsToReview('89890001K'));
        $requests = count($client->requests);
        self::assertSame(BatchSubmissionResult::STATE_PAUSED, $submitter->submitPending('89890001K')->state, 'Nothing is sent while paused');
        self::assertCount($requests, $client->requests);

        // Fixed: after the pause, the whole queue goes in one request.
        $aeat['broken'] = false;
        $this->clock->sleep(3601);
        self::assertSame(60, $submitter->submitPending('89890001K')->count(RecordSubmissionStatus::Accepted));
    }

    /**
     * LJD-M1: errors that are not about any record (AEAT Server fault, HTML or empty bodies, HTTP 401 / 403 / 404 /
     * 500 pages) never count: 60 records stay pending, none failed, no split.
     */
    public function testErrorsNotCausedByARecordNeverFailRecords(): void
    {
        $cases = [
            'Server 4118'  => static fn (): AeatBatchResponse => ScriptedBatchClient::fault(4118, 'env:Server'),
            'HTTP 403'     => static fn (): AeatBatchResponse => (new AeatResponseParser())->parse('<html><body>403 Forbidden</body></html>', 403),
            'HTTP 401'     => static fn (): AeatBatchResponse => (new AeatResponseParser())->parse('', 401),
            'HTTP 500 web' => static fn (): AeatBatchResponse => (new AeatResponseParser())->parse('<html><body>Servicio no disponible</body></html>', 500),
            'HTTP 404'     => static fn (): AeatBatchResponse => (new AeatResponseParser())->parse('<html><body>Not found</body></html>', 404),
        ];
        $i = 0;
        foreach ($cases as $name => $error) {
            $issuer = self::nif(100 + $i++);
            $this->fill(60, 1, AeatEnvironment::Sandbox, $issuer);
            /** @var list<Closure> $script */
            $script    = array_fill(0, 40, static fn (array $records): AeatBatchResponse => $error());
            $client    = new ScriptedBatchClient(...$script);
            $submitter = $this->submitter($client, maxAttempts: 1);

            self::assertNotContains(BatchSubmissionResult::STATE_PAUSED, $this->drive($submitter, 40, $issuer), $name);
            self::assertSame(array_fill(0, 40, 60), array_map('count', $client->requests), $name . ': never split');
            self::assertSame(array_fill(0, 60, 'pending'), $this->statuses($issuer), $name);
        }
    }

    /**
     * Circuit breaker: a lone record refused on its own cannot be told apart from an issuer-wide error (nothing to
     * probe with): it is never failed; the issuer is paused instead.
     */
    public function testALoneRecordTheAeatKeepsRefusingPausesTheIssuer(): void
    {
        $this->chain(1);
        $client    = new ScriptedBatchClient(...array_fill(0, 20, static fn (array $records): AeatBatchResponse => ScriptedBatchClient::fault()));
        $submitter = $this->submitter($client, maxAttempts: 3);

        $states = $this->drive($submitter, 20);

        self::assertSame(12, $submitter->failureLimit(), '9 halvings (1000 → 1) + 3');
        self::assertSame([...array_fill(0, 11, BatchSubmissionResult::STATE_FAILED), BatchSubmissionResult::STATE_PAUSED], array_slice($states, 0, 12));
        self::assertCount(12, array_filter($client->requests));
        self::assertSame(['pending'], $this->statuses());
    }

    /**
     * Circuit breaker: a probe without any answer (transport) decides nothing; the record stays held until a probe is
     * answered.
     */
    public function testAProbeWithoutAnswerKeepsTheRecordHeld(): void
    {
        $this->chain(2);
        $answers = [
            static fn (array $records): AeatBatchResponse => ScriptedBatchClient::fault(),
            static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('timeout', 0, '', '', true),
        ];
        $submitter = $this->submitter(new ScriptedBatchClient(...$answers), batchSize: 1, flowControl: false, maxAttempts: 1);

        self::assertTrue($submitter->submitPending('89890001K')->isRetryable());
        self::assertSame(['held', 'pending'], $this->statuses());
        self::assertTrue($submitter->submitPending('89890001K')->isRetryable());
        self::assertSame(['held', 'pending'], $this->statuses());
        $answered = $submitter->submitPending('89890001K');
        self::assertSame([1, [1]], [$answered->count(RecordSubmissionStatus::Accepted), array_map(static fn (StoredBillingRecord $s): int => $s->position, $answered->failed)]);
        self::assertSame(['failed', 'accepted'], $this->statuses());
    }

    /**
     * Circuit breaker: when another process resolved the held record meanwhile, the probe's answer fails nothing.
     */
    public function testAHeldRecordResolvedMeanwhileIsNotFailedTwice(): void
    {
        $this->chain(2);
        $answers = [
            static fn (array $records): AeatBatchResponse => ScriptedBatchClient::fault(),
            function (array $records): AeatBatchResponse {
                $this->store->resolveHeld('89890001K', false, $this->clock->now());

                return ScriptedBatchClient::allAccepted($records);
            },
        ];
        $submitter = $this->submitter(new ScriptedBatchClient(...$answers), batchSize: 1, flowControl: false, maxAttempts: 1);
        $submitter->submitPending('89890001K');

        self::assertSame([], $submitter->submitPending('89890001K')->failed);
        self::assertSame(['pending', 'accepted'], $this->statuses());
    }

    /**
     * LJD-L1: --retry-failed requeues only what the batch submitter failed; an AEAT `Incorrecto` stored as failed by
     * process(..., submitToAeat: true) is never sent again.
     */
    public function testOnlyRecordsTheSubmitterFailedAreRequeued(): void
    {
        $this->chain(1);
        $this->store->markSubmitted('89890001K', (string) iterator_to_array($this->store->findByIssuer('89890001K'))[0]->record->hash, false);
        $submitter = $this->submitter(new ScriptedBatchClient(), flowControl: false);

        self::assertSame(0, $submitter->requeueFailed('89890001K'));
        self::assertSame(['failed'], $this->statuses());
        self::assertSame(BatchSubmissionResult::STATE_IDLE, $submitter->submitPending('89890001K')->state);
    }

    /**
     * LJD-I1: unconfirmed records are listed and resolved after the host checks them at the AEAT.
     */
    public function testAnUnconfirmedRecordIsListedAndResolved(): void
    {
        $this->chain(2);
        $duplicate = static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_INCORRECT, array_values(array_map(
            static fn (BillingRecord $record): AeatRecordResponse => ScriptedBatchClient::line($record, RecordSubmissionStatus::Rejected, 3000, 'Duplicado', 'Correcta'),
            $records,
        )), null, 60);
        $submitter = $this->submitter(new ScriptedBatchClient(static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('timeout', 0, '', '', true), $duplicate), flowControl: false);
        $submitter->submitPending('89890001K');
        $submitter->submitPending('89890001K');

        self::assertSame([1, 2], array_map(static fn (StoredBillingRecord $s): int => $s->position, $submitter->recordsToReview('89890001K')));
        self::assertTrue($submitter->resolveUnconfirmed(' 89890001k ', 1, RecordSubmissionStatus::Accepted, 'CSV-CHECKED'));
        self::assertTrue($submitter->resolveUnconfirmed('89890001K', 2, RecordSubmissionStatus::Rejected, 'ignored'));
        self::assertFalse($submitter->resolveUnconfirmed('89890001K', 2, RecordSubmissionStatus::Accepted));
        self::assertFalse($submitter->resolveUnconfirmed('89890001K', 9, RecordSubmissionStatus::Accepted));
        $stored = iterator_to_array($this->store->findByIssuer('89890001K'));
        self::assertSame([['accepted', 'CSV-CHECKED'], ['rejected', null]], [[$stored[0]->submissionStatus, $stored[0]->submissionReference], [$stored[1]->submissionStatus, $stored[1]->submissionReference]]);

        $this->expectException(LogicException::class);
        $submitter->resolveUnconfirmed('89890001K', 1, RecordSubmissionStatus::Unconfirmed);
    }

    /**
     * When the claim was taken over during the request, the stale submitter holds and fails nothing; the newer claim
     * decides.
     */
    public function testAStaleClaimMarksNothingFailed(): void
    {
        $this->chain(1);
        $failedEvents = new ArrayObject();
        $this->dispatcher->addListener(VerifactuEvents::RECORD_SUBMISSION_FAILED, static function () use ($failedEvents): void {
            $failedEvents->append(true);
        });
        $client = new ScriptedBatchClient(function (array $records): AeatBatchResponse {
            // The request stalls past the claim timeout and another process takes the record over.
            $this->clock->sleep(301);
            $this->store->claimNext('89890001K', 1, 'taker', $this->clock->now(), 300);

            return ScriptedBatchClient::fault();
        });

        $result = $this->submitter($client, batchSize: 1, flowControl: false, maxAttempts: 1)->submitPending('89890001K');

        self::assertSame(1, $this->submitter($client, batchSize: 1, maxAttempts: 1)->failureLimit());
        self::assertSame([BatchSubmissionResult::STATE_FAILED, []], [$result->state, $result->failed]);
        self::assertSame(['sending'], $this->statuses());
        self::assertCount(0, $failedEvents);
    }

    /**
     * LJ-M3: no answer at all (transport) or an AEAT that is unavailable (502 / 503 / 504) is nobody's fault: the
     * records stay pending, no failure is counted and the batch is not split.
     */
    public function testATransportFailureOrAnUnavailableAeatNeverCountsAgainstTheRecords(): void
    {
        $this->chain(4);
        $answers = [];
        for ($i = 0; $i < 10; ++$i) {
            $answers[] = static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('Operation timed out', 0, '', '', true);
            $answers[] = static fn (array $records): AeatBatchResponse => (new AeatResponseParser())->parse('Service Unavailable', 503);
        }
        $client    = new ScriptedBatchClient(...$answers);
        $submitter = $this->submitter($client, flowControl: false, maxAttempts: 1);
        for ($i = 0; $i < 20; ++$i) {
            self::assertTrue($submitter->submitPending('89890001K')->isRetryable());
        }

        self::assertSame(array_fill(0, 20, 4), array_map('count', $client->requests));
        $stored = iterator_to_array($this->store->findByIssuer('89890001K'));
        self::assertSame(array_fill(0, 4, 'pending'), $this->statuses());
        self::assertSame([0, 20], [$stored[0]->submissionFailures, $stored[0]->submissionAttempts]);
    }

    /**
     * LJ-L1: with the global scope, the check and a reservation of the slot run under a lock shared by every issuer,
     * before the request: a second issuer checking while the first request is in flight waits.
     */
    public function testTheGlobalScopeReservesTheSlotBeforeTheRequest(): void
    {
        $this->chain(1);
        $this->chain(1, 1, '12345678Z');
        $lockable = new RecordingLockableHashChainRepository();
        $client   = new ScriptedBatchClient();
        $global   = $this->submitter($client, scope: 'global', heads: $lockable);
        $during   = null;
        $racing   = new ScriptedBatchClient(static function (array $records) use (&$during, $global): AeatBatchResponse {
            // Issuer 12345678Z tries while 89890001K's request is in flight (before its answer registers the wait).
            $during = $global->submitPending('12345678Z');

            return ScriptedBatchClient::allAccepted($records);
        });
        $first = $this->submitter($racing, scope: 'global', heads: $lockable, flowControl: $this->flow);

        self::assertSame(BatchSubmissionResult::STATE_SENT, $first->submitPending('89890001K')->state);
        self::assertInstanceOf(BatchSubmissionResult::class, $during);
        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $during->state);
        self::assertSame([], $client->requests);
        self::assertSame(['lock:*', 'lock:89890001K', 'unlock:89890001K', 'unlock:*'], array_slice($lockable->calls, 0, 4), 'Global lock first, issuer lock inside');

        // The issuer scope keeps one wait per issuer and takes no global lock.
        $lockable->calls = [];
        $this->submitter(new ScriptedBatchClient(), heads: $lockable)->submitPending('12345678Z');
        self::assertSame(['lock:12345678Z', 'unlock:12345678Z'], $lockable->calls);
    }

    /**
     * LJ-L3: records without an AEAT environment (written before 1.6) are never claimed: they could be sandbox records,
     * and the current environment may be production.
     */
    public function testRecordsWithoutAnEnvironmentAreNeverSent(): void
    {
        $this->store->append(new BillingRecord(RecordType::Alta, '89890001K', 'OLD-1', '09-07-2026', 'F1', '21.00', '121.00', '', hash: 'AA11', xml: '<x/>'));
        $client    = new ScriptedBatchClient();
        $submitter = $this->submitter($client, flowControl: false);

        self::assertSame([], $submitter->issuersWithPendingRecords());
        self::assertSame(BatchSubmissionResult::STATE_IDLE, $submitter->submitPending('89890001K')->state);

        $this->chain(1);
        self::assertSame(['89890001K'], $submitter->issuersWithPendingRecords());
        self::assertSame(1, $submitter->submitPending('89890001K')->count(RecordSubmissionStatus::Accepted));
        self::assertSame(['F-1'], array_map(static fn (BillingRecord $r): string => $r->invoiceSeriesNumber, $client->requests[0]));
        self::assertSame(['pending', 'accepted'], $this->statuses());
        self::assertSame(BatchSubmissionResult::STATE_IDLE, $submitter->submitPending('89890001K')->state);
    }

    /**
     * Runs the submitter like a scheduler, one call a minute (past every AEAT wait), and returns the states.
     *
     * @return list<string>
     */
    private function drive(BillingRecordBatchSubmitter $submitter, int $calls, string $issuer = TestProcessorFactory::ISSUER): array
    {
        $states = [];
        for ($i = 0; $i < $calls; ++$i) {
            $state = $submitter->submitPending($issuer)->state;
            if ($state === BatchSubmissionResult::STATE_IDLE) {
                break;
            }
            $states[] = $state;
            $this->clock->sleep(61);
        }

        return $states;
    }

    private static function nif(int $i): string
    {
        $number = 20000000 + $i;

        return $number . 'TRWAGMYFPDXBNJZSQVHLCKE'[$number % 23];
    }

    /**
     * Stores records directly (no chaining): cheap queues of up to 1000 records for the flow control tests.
     */
    private function fill(int $count, int $first = 1, AeatEnvironment $environment = AeatEnvironment::Sandbox, string $issuer = TestProcessorFactory::ISSUER): void
    {
        for ($i = $first; $i < $first + $count; ++$i) {
            $this->store->append(new BillingRecord(RecordType::Alta, $issuer, 'Q-' . $i, '09-07-2026', 'F1', '21.00', '121.00', '', hash: strtoupper(hash('sha256', $issuer . $i)), xml: '<x/>', environment: $environment));
        }
    }

    private function confirmation(?bool $same, bool $throws = false): RecordingDuplicateConfirmation
    {
        return new RecordingDuplicateConfirmation($same, $throws);
    }

    /**
     * @return list<string>
     */
    private function statuses(string $issuer = TestProcessorFactory::ISSUER): array
    {
        return array_values(array_map(static fn (StoredBillingRecord $s): string => $s->submissionStatus, iterator_to_array($this->store->findByIssuer($issuer))));
    }

    private function chain(int $count, int $first = 1, string $issuer = TestProcessorFactory::ISSUER): void
    {
        for ($i = $first; $i < $first + $count; ++$i) {
            $result = $this->processor->process(TestProcessorFactory::record('F-' . $i, $issuer));
            self::assertSame([], $result['errors']);
        }
    }

    private function submitter(
        ScriptedBatchClient $client,
        int $batchSize = 1000,
        CacheFlowControl|false|null $flowControl = null,
        string $scope = 'issuer',
        ?DuplicateConfirmationInterface $confirmation = null,
        int $maxAttempts = 5,
        ?HashChainRepositoryInterface $heads = null,
        ?LoggerInterface $logger = null,
    ): BillingRecordBatchSubmitter {
        if ($flowControl === null) {
            $this->flow = new CacheFlowControl(new ArrayAdapter());
        }

        return new BillingRecordBatchSubmitter(
            $this->store,
            $client,
            $heads ?? $this->heads,
            $this->dispatcher,
            $flowControl === false ? null : ($flowControl ?? $this->flow),
            $this->clock,
            $logger,
            $batchSize,
            300,
            60,
            $scope,
            $confirmation,
            $maxAttempts,
        );
    }
}
