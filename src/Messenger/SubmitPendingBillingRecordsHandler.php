<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Messenger;

use DateTimeImmutable;
use Nowo\VerifactuBundle\Model\BatchSubmissionResult;
use Nowo\VerifactuBundle\Service\BillingRecordBatchSubmitter;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

use function max;

/**
 * Sends the next batch of the issuer and schedules the following one (registered only when symfony/messenger is
 * installed). Stateless: everything it needs is in the message, the record log and the flow control store.
 *
 * - Records still waiting, or the AEAT wait time not elapsed: the message is dispatched again with a DelayStamp until
 *   the next allowed submission.
 * - A batch without an AEAT answer (transport error, SoapFault): its records went back to pending; the message is
 *   dispatched again after the wait time (the resend is idempotent).
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

    public function __construct(
        private readonly BillingRecordBatchSubmitter $submitter,
        private readonly ?MessageBusInterface $bus = null,
        private readonly ?ClockInterface $clock = null,
    ) {
    }

    public function __invoke(SubmitPendingBillingRecords $message): BatchSubmissionResult
    {
        $result = $this->submitter->submitPending($message->issuerNif);
        $now    = $this->clock?->now() ?? new DateTimeImmutable();

        $delay = match (true) {
            $result->state === BatchSubmissionResult::STATE_IN_FLIGHT                        => self::RETRY_IN_FLIGHT_SECONDS,
            $result->state === BatchSubmissionResult::STATE_IDLE                             => null,
            $result->state === BatchSubmissionResult::STATE_SENT && $result->remaining === 0 => null,
            default                                                                          => max(0, ($result->nextSubmissionAt?->getTimestamp() ?? $now->getTimestamp()) - $now->getTimestamp()),
        };
        if ($delay !== null && $this->bus instanceof MessageBusInterface) {
            $this->bus->dispatch(new SubmitPendingBillingRecords($result->issuerNif), [new DelayStamp($delay * 1000)]);
        }

        return $result;
    }
}
