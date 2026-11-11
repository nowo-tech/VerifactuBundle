<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once per record after the batch submitter has COMMITTED it as `failed` (VerifactuEvents::
 * RECORD_SUBMISSION_FAILED): the AEAT refused, as a whole, every request that carried the record on its own (a
 * SoapFault, an HTTP 4xx / 500 or an unreadable answer) `submission.max_attempts` times. The record is not sent again
 * until the cause is fixed and it is put back with `nowo:verifactu:submit-pending --retry-failed` (or
 * SubmissionQueueInterface::requeueFailed()); the records after it keep flowing. Alert someone: the invoice is not at
 * the AEAT. A listener failure never changes the stored state.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RecordSubmissionFailedEvent extends Event
{
    /**
     * @param string $failure the last AEAT failure (SoapFault text, HTTP status)
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly int $position,
        public readonly string $hash,
        public readonly string $failure,
        public readonly StoredBillingRecord $stored,
        public readonly AeatBatchResponse $response,
    ) {
    }
}
