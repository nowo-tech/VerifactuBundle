<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\StoredBillingRecord;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once per record after the batch submitter has COMMITTED it as `failed` (VerifactuEvents::
 * RECORD_SUBMISSION_FAILED): the AEAT refused the record on its own (a SOAP `Client` fault) with a record-content code,
 * or again after another record sent alone was answered (`response` is that refusal); or an operator marked it failed
 * with `submit-pending --fail` (`response` is null). The record is not sent again
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
        public readonly ?AeatBatchResponse $response,
    ) {
    }
}
