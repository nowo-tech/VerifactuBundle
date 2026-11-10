<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use Nowo\VerifactuBundle\Model\BillingRecord;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after the AEAT submission client returned, with the submission result.
 *
 * Informational only: the result returned by `process()` is not affected by listeners. The record is a copy of the one
 * that was sent. The result holds what the submission client returned (`success`, `reference`, `errors` and, for the SOAP
 * client, `endpoint`, `status_code`, `raw_response`); it never contains the certificate or its password. Not dispatched
 * when the client throws.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class AfterAeatSubmissionEvent extends Event
{
    /**
     * Wraps a copy of the submitted billing record and the submission result.
     *
     * @param array<string, mixed> $submission
     */
    public function __construct(
        private readonly BillingRecord $record,
        private readonly array $submission,
    ) {
    }

    /**
     * Returns a copy of the submitted billing record.
     */
    public function getRecord(): BillingRecord
    {
        return $this->record;
    }

    /**
     * Returns the submission result as returned by the AEAT submission client.
     *
     * @return array<string, mixed>
     */
    public function getSubmission(): array
    {
        return $this->submission;
    }

    /**
     * Whether the AEAT accepted the record (`success` is true).
     */
    public function isSuccessful(): bool
    {
        return ($this->submission['success'] ?? false) === true;
    }
}
