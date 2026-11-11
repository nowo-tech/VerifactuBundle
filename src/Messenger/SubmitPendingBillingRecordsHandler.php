<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Messenger;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

use function max;
use function min;

/**
 * Sends the next batch of the issuer and schedules the following one (registered only when symfony/messenger is
 * installed). Stateless: everything it needs is in the message, the record log and the flow control store.
 *
 * - Records still waiting, or the AEAT wait time not elapsed: the message is dispatched again with a DelayStamp until
 *   the next allowed submission.
 * - The AEAT refused the batch as a whole (a SOAP Client fault): rescheduled after the wait time; the submitter splits
 *   the batch and finally marks the record at fault failed, or pauses the issuer, so this always ends.
 * - The issuer is paused (circuit breaker, ISSUER_SUBMISSION_PAUSED): the message stops; the next message for the
 *   issuer or `nowo:verifactu:submit-pending` after the pause sends again.
 * - No AEAT answer at all (transport error, Server fault, HTTP or non-SOAP error): rescheduled with a growing delay (up to
 *   MAX_RETRY_DELAY_SECONDS), at most MAX_FAILED_RUNS times in a row; then it stops and logs an error. The records stay
 *   pending: the next message for the issuer or `nowo:verifactu:submit-pending` sends them.
 * - Another process sending a batch of the issuer: retried after RETRY_IN_FLIGHT_SECONDS.
 * - An exception (no certificate, database down) propagates: Messenger's retry strategy and failure transport apply,
 *   and the records stay pending.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[AsMessageHandler]
final class SubmitPendingBillingRecordsHandler
{
    public const RETRY_IN_FLIGHT_SECONDS = 30;

    /** Consecutive runs without any AEAT answer after which the message stops rescheduling itself. */
    public const MAX_FAILED_RUNS = 8;

    /** First retry delay after a run without any AEAT answer; doubled on every further one. */
    public const RETRY_DELAY_SECONDS = 60;

    public const MAX_RETRY_DELAY_SECONDS = 3600;

    public function __construct(
        private readonly BillingRecordBatchSubmitter $submitter,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?ClockInterface $clock = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function __invoke(SubmitPendingBillingRecords $message): BatchSubmissionResult
    {
        $result     = $this->submitter->submitPending($message->issuerNif);
        $now        = $this->clock?->now() ?? new DateTimeImmutable();
        $untilNext  = max(0, ($result->nextSubmissionAt?->getTimestamp() ?? $now->getTimestamp()) - $now->getTimestamp());
        $noAnswer   = $result->isRetryable() && $result->response?->isRecordRefusal() !== true;
        $failedRuns = $noAnswer ? $message->failedRuns + 1 : 0;

        $delay = match (true) {
            $result->state === BatchSubmissionResult::STATE_IN_FLIGHT                          => self::RETRY_IN_FLIGHT_SECONDS,
            $result->state === BatchSubmissionResult::STATE_IDLE                               => null,
            $result->state === BatchSubmissionResult::STATE_PAUSED                             => null,
            $result->state === BatchSubmissionResult::STATE_SENT && $result->remaining === 0   => null,
            $noAnswer && $failedRuns >= self::MAX_FAILED_RUNS                                  => null,
            $noAnswer                                                                          => max($untilNext, min(self::MAX_RETRY_DELAY_SECONDS, self::RETRY_DELAY_SECONDS * 2 ** ($failedRuns - 1))),
            $result->state === BatchSubmissionResult::STATE_FAILED && $result->remaining === 0 => null,
            default                                                                            => $untilNext,
        };
        if ($noAnswer && $delay === null) {
            $this->logger?->error('The AEAT gave no answer to {runs} submissions of {issuer} in a row; the Messenger message stops rescheduling itself. The records stay pending: dispatch the message again or run nowo:verifactu:submit-pending.', [
                'runs'   => $failedRuns,
                'issuer' => $result->issuerNif,
            ]);
        }
        if ($delay !== null && $this->bus instanceof MessageBusInterface) {
            $this->bus->dispatch(new SubmitPendingBillingRecords($result->issuerNif, $failedRuns), [new DelayStamp($delay * 1000)]);
        }

        return $result;
    }
}
