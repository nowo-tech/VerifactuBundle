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
 * - STATE_FAILED: the batch got no answer (transport error, HTTP status, SoapFault): every record went back to pending
 *   and may be sent again (`retryable`).
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

    /**
     * @param list<RecordSubmissionOutcome> $outcomes
     * @param list<array{event: string, exception: Throwable}> $eventErrors
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly string $state,
        public readonly array $outcomes = [],
        public readonly ?AeatBatchResponse $response = null,
        public readonly ?DateTimeImmutable $nextSubmissionAt = null,
        public readonly int $remaining = 0,
        public readonly array $eventErrors = [],
    ) {
    }

    /**
     * Whether the batch got no answer and its records may be sent again.
     */
    public function isRetryable(): bool
    {
        return $this->state === self::STATE_FAILED;
    }

    /**
     * Number of records of the batch with the given status (null = no answer for them).
     */
    public function count(?RecordSubmissionStatus $status): int
    {
        return count(array_filter($this->outcomes, static fn (RecordSubmissionOutcome $outcome): bool => $outcome->status === $status));
    }
}
