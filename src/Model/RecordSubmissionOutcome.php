<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * What a batch submission learnt about one stored record: its AEAT status (null = no answer for it, back to pending),
 * the CSV of the submission and the AEAT error, if any.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RecordSubmissionOutcome
{
    public function __construct(
        public readonly StoredBillingRecord $stored,
        public readonly ?RecordSubmissionStatus $status,
        public readonly ?string $reference = null,
        public readonly ?int $errorCode = null,
        public readonly ?string $errorDescription = null,
        public readonly ?AeatRecordResponse $line = null,
    ) {
    }

    /**
     * The record log status for this outcome.
     */
    public function storeStatus(): string
    {
        return match ($this->status) {
            RecordSubmissionStatus::Accepted           => StoredBillingRecord::STATUS_ACCEPTED,
            RecordSubmissionStatus::AcceptedWithErrors => StoredBillingRecord::STATUS_ACCEPTED_WITH_ERRORS,
            RecordSubmissionStatus::Rejected           => StoredBillingRecord::STATUS_REJECTED,
            null                                       => StoredBillingRecord::STATUS_PENDING,
        };
    }
}
