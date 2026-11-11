<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Messenger;

use Closure;
use DateTimeImmutable;
use Nowo\VerifactuBundle\Messenger\SubmitPendingBillingRecords;
use Nowo\VerifactuBundle\Messenger\SubmitPendingBillingRecordsHandler;
use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Submission\CacheFlowControl;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class SubmitPendingBillingRecordsHandlerTest extends TestCase
{
    /** @var list<Envelope> */
    private array $dispatched = [];

    private InMemoryHashChainRepository $heads;

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
     * @return array{SubmitPendingBillingRecordsHandler, InMemoryBillingRecordStore}
     */
    private function handler(ScriptedBatchClient $client, int $batchSize = 1000): array
    {
        $heads = new InMemoryHashChainRepository();
        $store = new InMemoryBillingRecordStore();
        $clock = new MockClock('2026-11-11 10:00:00');
        $sink  = function (Envelope $envelope): void {
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
        $submitter   = new BillingRecordBatchSubmitter($store, $client, $heads, new EventDispatcher(), new CacheFlowControl(new ArrayAdapter()), $clock, null, $batchSize);
        $this->heads = $heads;

        return [new SubmitPendingBillingRecordsHandler($submitter, $bus, $clock), $store];
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
