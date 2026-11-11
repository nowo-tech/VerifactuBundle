<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

use DateTimeImmutable;
use Throwable;

use function count;

/**
 * The result of one BillingRecordBatchSubmitter::submitPending() call.
 *
 * - STATE_IDLE: nothing waiting for the issuer.
 * - STATE_IN_FLIGHT: another process is sending a batch of the issuer; try again later.
 * - STATE_THROTTLED: the AEAT wait time has not elapsed and fewer than a full batch is waiting (`nextSubmissionAt`).
 * - STATE_SENT: a batch got an AEAT answer; `outcomes` holds one entry per record (status null = no answer for it).
 * - STATE_FAILED: the batch got no per-record answer (transport error, HTTP status, SoapFault). Its records went back to
 *   pending and may be sent again (`retryable`), or, for a record the AEAT kept refusing on its own, it was marked
 *   failed (`failed`, RECORD_SUBMISSION_FAILED) and the next records flow.
 * - STATE_PAUSED: the circuit breaker paused the issuer until `nextSubmissionAt` (ISSUER_SUBMISSION_PAUSED,
 *   `pauseReason` when this call paused it): the AEAT refuses every request of it, or suspect records wait for another
 *   record to probe with. No record was failed.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BatchSubmissionResult
{
    public const STATE_IDLE      = 'idle';
    public const STATE_IN_FLIGHT = 'in_flight';
    public const STATE_THROTTLED = 'throttled';
    public const STATE_SENT      = 'sent';
    public const STATE_FAILED    = 'failed';

    /** Circuit breaker: the AEAT refuses every request of the issuer; nothing is sent until `nextSubmissionAt`. */
    public const STATE_PAUSED = 'paused';

    /**
     * @param list<RecordSubmissionOutcome> $outcomes
     * @param list<array{event: string, exception: Throwable}> $eventErrors
     * @param list<StoredBillingRecord> $failed records marked failed by this call
     * @param string|null $pauseReason why this call paused the issuer (IssuerSubmissionPausedEvent::REASON_*)
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $state,
        public readonly array $outcomes = [],
        public readonly ?AeatBatchResponse $response = null,
        public readonly ?DateTimeImmutable $nextSubmissionAt = null,
        public readonly int $remaining = 0,
        public readonly array $eventErrors = [],
        public readonly array $failed = [],
        public readonly ?string $pauseReason = null,
    ) {
    }

    /**
     * Whether the batch got no answer and its records may be sent again.
     */
    public function isRetryable(): bool
    {
        return $this->state === self::STATE_FAILED && $this->failed === [];
    }

    /**
     * Number of records of the batch with the given status (null = no answer for them).
     */
    public function count(?RecordSubmissionStatus $status): int
    {
        return count(array_filter($this->outcomes, static fn (RecordSubmissionOutcome $outcome): bool => $outcome->status === $status));
    }
}
