<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use DateTimeImmutable;
use LogicException;
use Nowo\VerifactuBundle\Event\RecordSubmittedEvent;
use Nowo\VerifactuBundle\Event\VerifactuEvents;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\AeatEnvironment;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Model\RecordSubmissionStatus;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Provider\AeatEnvironmentProviderInterface;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Repository\SubmissionQueueInterface;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Service\BillingRecordProcessor;
use Nowo\VerifactuBundle\Submission\CacheFlowControl;
use Nowo\VerifactuBundle\Tests\Support\RecordingLockableHashChainRepository;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
        self::assertSame(2, $retry->count(RecordSubmissionStatus::Accepted));
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

    public function testADuplicateOfAnAnnulledRecordIsRejected(): void
    {
        $this->chain(1);
        $client = new ScriptedBatchClient(
            static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('reset by peer'),
            static fn (array $records): AeatBatchResponse => new AeatBatchResponse(AeatBatchResponse::ENVIO_INCORRECT, [
                ScriptedBatchClient::line($records[0], RecordSubmissionStatus::Rejected, 3000, 'Registro de facturación duplicado.', 'Anulada'),
            ], null, 60),
        );
        $submitter = $this->submitter($client, flowControl: false);
        $submitter->submitPending('89890001K');

        self::assertSame(1, $submitter->submitPending('89890001K')->count(RecordSubmissionStatus::Rejected));
    }

    public function testTheAeatWaitTimeIsHonouredUnlessAFullBatchIsWaiting(): void
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

        // A full batch may go before the wait time ends (art. 16.2 c).
        $this->chain(1, 4);
        self::assertSame(BatchSubmissionResult::STATE_SENT, $submitter->submitPending('89890001K')->state);
        self::assertCount(2, $client->requests);

        $this->chain(1, 5);
        $this->clock->sleep(59);
        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $submitter->submitPending('89890001K')->state);
        $this->clock->sleep(1);
        self::assertSame(BatchSubmissionResult::STATE_SENT, $submitter->submitPending('89890001K')->state);
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

    private function chain(int $count, int $first = 1, string $issuer = TestProcessorFactory::ISSUER): void
    {
        for ($i = $first; $i < $first + $count; ++$i) {
            $result = $this->processor->process(TestProcessorFactory::record('F-' . $i, $issuer));
            self::assertSame([], $result['errors']);
        }
    }

    private function submitter(ScriptedBatchClient $client, int $batchSize = 1000, CacheFlowControl|false|null $flowControl = null, string $scope = 'issuer'): BillingRecordBatchSubmitter
    {
        return new BillingRecordBatchSubmitter(
            $this->store,
            $client,
            $this->heads,
            $this->dispatcher,
            $flowControl === false ? null : ($flowControl ?? new CacheFlowControl(new ArrayAdapter())),
            $this->clock,
            null,
            $batchSize,
            300,
            60,
            $scope,
        );
    }
}
