<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Messenger;

use Closure;
use DateTimeImmutable;
use Nowo\VerifactuBundle\Messenger\SubmitPendingBillingRecords;
use Nowo\VerifactuBundle\Messenger\SubmitPendingBillingRecordsHandler;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Model\BillingRecord;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Submission\CacheFlowControl;
use Nowo\VerifactuBundle\Tests\Support\RecordingLogger;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

use function count;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SubmitPendingBillingRecordsHandlerTest extends TestCase
{
    /** @var list<Envelope> */
    private array $dispatched = [];

    private InMemoryHashChainRepository $heads;

    private ?MockClock $clock = null;

    public function testItSendsAndSchedulesTheNextBatchAfterTheWaitTime(): void
    {
        [$handler, $store] = $this->handler(new ScriptedBatchClient(), 2);
        $this->chain($store, 3);

        $result = $handler(new SubmitPendingBillingRecords('89890001K'));

        self::assertSame(BatchSubmissionResult::STATE_SENT, $result->state);
        self::assertSame(1, $result->remaining);
        self::assertCount(1, $this->dispatched);
        self::assertSame(60000, $this->dispatched[0]->last(DelayStamp::class)?->getDelay());
        self::assertSame('89890001K', $this->message(0)->issuerNif);
    }

    public function testNothingIsScheduledWhenEverythingWasSent(): void
    {
        [$handler, $store] = $this->handler(new ScriptedBatchClient());
        $this->chain($store, 2);

        $handler(new SubmitPendingBillingRecords('89890001K'));
        $handler(new SubmitPendingBillingRecords('89890001K'));

        self::assertSame([], $this->dispatched);
    }

    public function testAFailureIsRetriedAfterTheWaitAndAThrottledIssuerWhenAllowed(): void
    {
        [$handler, $store] = $this->handler(new ScriptedBatchClient(static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('timeout')));
        $this->chain($store, 1);

        self::assertTrue($handler(new SubmitPendingBillingRecords('89890001K'))->isRetryable());
        self::assertSame(60000, $this->dispatched[0]->last(DelayStamp::class)?->getDelay());

        self::assertSame(BatchSubmissionResult::STATE_THROTTLED, $handler(new SubmitPendingBillingRecords('89890001K'))->state);
        self::assertSame(60000, $this->dispatched[1]->last(DelayStamp::class)?->getDelay());
    }

    public function testAnIssuerInFlightIsRetriedLater(): void
    {
        [$handler, $store] = $this->handler(new ScriptedBatchClient());
        $this->chain($store, 1);
        $store->claimNext('89890001K', 10, 'other', new DateTimeImmutable('2026-11-11 10:00:00'), 300);

        self::assertSame(BatchSubmissionResult::STATE_IN_FLIGHT, $handler(new SubmitPendingBillingRecords('89890001K'))->state);
        self::assertSame(SubmitPendingBillingRecordsHandler::RETRY_IN_FLIGHT_SECONDS * 1000, $this->dispatched[0]->last(DelayStamp::class)?->getDelay());
    }

    /**
     * LJ-M3: without any AEAT answer the message backs off and stops after MAX_FAILED_RUNS runs in a row (error logged);
     * the records stay pending for the next message or the command.
     */
    public function testRunsWithoutAnyAnswerBackOffAndStop(): void
    {
        $timeout           = static fn (array $records): AeatBatchResponse => AeatBatchResponse::failed('timeout', 0, '', '', true);
        $logger            = new RecordingLogger();
        [$handler, $store] = $this->handler(new ScriptedBatchClient(...array_fill(0, 20, $timeout)), logger: $logger, flowControl: false);
        $this->chain($store, 1);

        $message = new SubmitPendingBillingRecords('89890001K');
        $delays  = [];
        for ($run = 1; $run <= SubmitPendingBillingRecordsHandler::MAX_FAILED_RUNS; ++$run) {
            $before = count($this->dispatched);
            self::assertTrue($handler($message)->isRetryable());
            if (count($this->dispatched) === $before) {
                break;
            }
            $message  = $this->message(count($this->dispatched) - 1);
            $delays[] = $this->dispatched[count($this->dispatched) - 1]->last(DelayStamp::class)?->getDelay();
            self::assertSame($run, $message->failedRuns);
        }

        self::assertSame([60000, 120000, 240000, 480000, 960000, 1920000, 3600000], $delays);
        self::assertCount(SubmitPendingBillingRecordsHandler::MAX_FAILED_RUNS - 1, $this->dispatched);
        self::assertSame('error', $logger->records[count($logger->records) - 1]['level']);
        self::assertSame(['pending'], array_map(static fn ($s): string => $s->submissionStatus, iterator_to_array($store->findByIssuer('89890001K'))));
    }

    /**
     * LJ-M3: an AEAT refusal of the whole batch keeps the message going (the submitter splits the batch and finally
     * marks the record at fault failed); an answer resets the count of runs without answer.
     */
    public function testARefusedBatchKeepsGoingUntilTheRecordAtFaultIsFailed(): void
    {
        // Every request carrying F-1 is refused as a whole.
        $fault = static function (array $records): AeatBatchResponse {
            foreach ($records as $record) {
                if ($record instanceof BillingRecord && $record->invoiceSeriesNumber === 'F-1') {
                    return ScriptedBatchClient::fault();
                }
            }

            return ScriptedBatchClient::allAccepted($records);
        };
        /** @var list<Closure> $script */
        $script            = array_fill(0, 40, $fault);
        [$handler, $store] = $this->handler(new ScriptedBatchClient(...$script), flowControl: false);
        $this->chain($store, 2);

        $message = new SubmitPendingBillingRecords('89890001K', 5);
        for ($i = 0; $i < 40 && $message instanceof SubmitPendingBillingRecords; ++$i) {
            $before = count($this->dispatched);
            $handler($message);
            $message = count($this->dispatched) > $before ? $this->message(count($this->dispatched) - 1) : null;
            self::assertTrue($message === null || $message->failedRuns === 0);
        }

        self::assertNull($message, 'The message stopped by itself');
        self::assertSame(['failed', 'accepted'], array_map(static fn ($s): string => $s->submissionStatus, iterator_to_array($store->findByIssuer('89890001K'))));
    }

    /**
     * LJD2-L4: a paused issuer is dispatched again once, at the end of the pause; when the AEAT answers again the queue
     * is sent.
     */
    public function testAPausedIssuerIsRescheduledAtTheEndOfThePause(): void
    {
        $fault             = static fn (array $records): AeatBatchResponse => ScriptedBatchClient::fault(4104);
        [$handler, $store] = $this->handler(new ScriptedBatchClient($fault, $fault));
        $this->chain($store, 2);

        self::assertSame(BatchSubmissionResult::STATE_PAUSED, $handler(new SubmitPendingBillingRecords('89890001K'))->state);
        self::assertCount(1, $this->dispatched);
        self::assertSame(3600000, $this->dispatched[0]->last(DelayStamp::class)?->getDelay(), 'Once, at the end of the pause');

        // Delivered early (another message): still paused, rescheduled at the end of the same pause.
        $this->clock?->sleep(600);
        self::assertSame(BatchSubmissionResult::STATE_PAUSED, $handler($this->message(0))->state);
        self::assertSame([3600000, 3000000], array_map(static fn (Envelope $e): ?int => $e->last(DelayStamp::class)?->getDelay(), $this->dispatched));

        // At the end of the pause, still refused: paused again, one more message.
        $this->clock?->sleep(3000);
        self::assertSame(BatchSubmissionResult::STATE_PAUSED, $handler($this->message(1))->state);
        self::assertCount(3, $this->dispatched);

        // Fixed: sent, and the message stops.
        $this->clock?->sleep(3600);
        self::assertSame(BatchSubmissionResult::STATE_SENT, $handler($this->message(2))->state);
        self::assertCount(3, $this->dispatched);
        self::assertSame(['accepted', 'accepted'], array_map(static fn ($s): string => $s->submissionStatus, iterator_to_array($store->findByIssuer('89890001K'))));
    }

    /**
     * @return array{SubmitPendingBillingRecordsHandler, InMemoryBillingRecordStore}
     */
    private function handler(ScriptedBatchClient $client, int $batchSize = 1000, ?RecordingLogger $logger = null, bool $flowControl = true): array
    {
        $heads       = new InMemoryHashChainRepository();
        $store       = new InMemoryBillingRecordStore();
        $clock       = new MockClock('2026-11-11 10:00:00');
        $this->clock = $clock;
        $sink        = function (Envelope $envelope): void {
            $this->dispatched[] = $envelope;
        };
        $bus = new class($sink) implements MessageBusInterface {
            /** @param Closure(Envelope): void $sink */
            public function __construct(private readonly Closure $sink)
            {
            }

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $envelope = Envelope::wrap($message, $stamps);
                ($this->sink)($envelope);

                return $envelope;
            }
        };
        $submitter   = new BillingRecordBatchSubmitter($store, $client, $heads, new EventDispatcher(), $flowControl ? new CacheFlowControl(new ArrayAdapter()) : null, $clock, null, $batchSize);
        $this->heads = $heads;

        return [new SubmitPendingBillingRecordsHandler($submitter, $bus, $clock, $logger), $store];
    }

    private function chain(InMemoryBillingRecordStore $store, int $count): void
    {
        $processor = TestProcessorFactory::create($this->heads, $store);
        for ($i = 1; $i <= $count; ++$i) {
            self::assertSame([], $processor->process(TestProcessorFactory::record('F-' . $i))['errors']);
        }
    }

    private function message(int $index): SubmitPendingBillingRecords
    {
        $message = $this->dispatched[$index]->getMessage();
        self::assertInstanceOf(SubmitPendingBillingRecords::class, $message);

        return $message;
    }
}
