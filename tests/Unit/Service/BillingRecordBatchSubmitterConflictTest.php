<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Tests\Unit\Service;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\VerifactuBundle\Exception\ChainTransactionLostException;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Model\HashChainState;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Nowo\VerifactuBundle\Repository\InMemoryBillingRecordStore;
use Nowo\VerifactuBundle\Repository\InMemoryHashChainRepository;
use Nowo\VerifactuBundle\Repository\LockableHashChainRepositoryInterface;
use Nowo\VerifactuBundle\Repository\SubmissionQueueInterface;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Nowo\VerifactuBundle\Tests\Support\ScriptedBatchClient;
use Nowo\VerifactuBundle\Tests\Support\TestProcessorFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Lock conflicts in the batch submitter: the AEAT answers are stored again in-process (the claim token makes a replay
 * harmless), and a transaction lost inside the submitter's own nested locks comes out as the database error itself.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordBatchSubmitterConflictTest extends TestCase
{
    private InMemoryHashChainRepository $heads;

    private InMemoryBillingRecordStore $store;

    protected function setUp(): void
    {
        $this->heads = new InMemoryHashChainRepository();
        $this->store = new InMemoryBillingRecordStore();
        $processor   = TestProcessorFactory::create($this->heads, $this->store);
        self::assertSame([], $processor->process(TestProcessorFactory::record('F-1'))['errors']);
    }

    public function testTheAnswersAreStoredAgainAfterALockConflict(): void
    {
        $calls = 0;
        $queue = $this->forwardingQueue();
        $queue->method('recordOutcomes')->willReturnCallback(function (...$arguments) use (&$calls): array {
            if (++$calls < BillingRecordBatchSubmitter::RECORD_OUTCOMES_ATTEMPTS) {
                throw self::deadlock();
            }

            return $this->store->recordOutcomes(...$arguments);
        });

        $result = $this->submitter($queue)->submitPending(TestProcessorFactory::ISSUER);

        self::assertSame(BatchSubmissionResult::STATE_SENT, $result->state);
        self::assertSame(BillingRecordBatchSubmitter::RECORD_OUTCOMES_ATTEMPTS, $calls);
        self::assertSame(StoredBillingRecord::STATUS_ACCEPTED, iterator_to_array($this->store->findByIssuer(TestProcessorFactory::ISSUER), false)[0]->submissionStatus);
    }

    public function testALockConflictOnEveryAttemptIsRethrown(): void
    {
        $failure = self::deadlock();
        $queue   = $this->forwardingQueue();
        $queue->expects(self::exactly(BillingRecordBatchSubmitter::RECORD_OUTCOMES_ATTEMPTS))->method('recordOutcomes')->willThrowException($failure);

        $this->expectExceptionObject($failure);
        $this->submitter($queue)->submitPending(TestProcessorFactory::ISSUER);
    }

    public function testALostOwnLockTransactionComesOutAsTheDatabaseError(): void
    {
        $failure = self::deadlock();
        $heads   = $this->createMock(LockableHashChainRepositoryInterface::class);
        $heads->method('withChainLock')->willThrowException(new ChainTransactionLostException($failure));

        $this->expectExceptionObject($failure);
        $this->submitter($this->store, $heads)->submitPending(TestProcessorFactory::ISSUER);
    }

    public function testALostOwnLockTransactionWithoutCauseStaysAsItIs(): void
    {
        $lost  = new ChainTransactionLostException();
        $heads = $this->createMock(LockableHashChainRepositoryInterface::class);
        $heads->method('withChainLock')->willThrowException($lost);
        $heads->method('getLastState')->willReturn(new HashChainState(TestProcessorFactory::ISSUER, 'F-1', '09-07-2026', str_repeat('A', 64)));

        $this->expectExceptionObject($lost);
        $this->submitter($this->store, $heads)->submitPending(TestProcessorFactory::ISSUER);
    }

    private static function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found when trying to get lock', '40001', 1213) extends AbstractException {}, null);
    }

    /**
     * A queue mock that forwards every method but recordOutcomes() to the in-memory store.
     *
     * @return MockObject&SubmissionQueueInterface
     */
    private function forwardingQueue(): MockObject
    {
        $queue = $this->createMock(SubmissionQueueInterface::class);
        foreach ((new ReflectionClass(SubmissionQueueInterface::class))->getMethods() as $method) {
            $name = $method->getName();
            if ($name !== 'recordOutcomes') {
                $queue->method($name)->willReturnCallback(fn (mixed ...$arguments): mixed => $this->store->{$name}(...$arguments));
            }
        }

        return $queue;
    }

    private function submitter(SubmissionQueueInterface $queue, ?LockableHashChainRepositoryInterface $heads = null): BillingRecordBatchSubmitter
    {
        return new BillingRecordBatchSubmitter($queue, new ScriptedBatchClient(), $heads ?? $this->heads, new EventDispatcher(), null, null, null, 1000, 300, 0);
    }
}
