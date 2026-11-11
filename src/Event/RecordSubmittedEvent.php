<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

use Nowo\VerifactuBundle\Model\AeatBatchResponse;
use Nowo\VerifactuBundle\Model\RecordSubmissionOutcome;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched once per record after the batch submitter has COMMITTED the AEAT answer for it in the record log
 * (VerifactuEvents::AFTER_RECORD_SUBMISSION). The outcome carries the record (a copy), its new status (accepted,
 * accepted with errors or rejected), the CSV and the AEAT error. A listener failure never changes the stored outcome.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class RecordSubmittedEvent extends Event
{
    public function __construct(
        public readonly RecordSubmissionOutcome $outcome,
        public readonly AeatBatchResponse $response,
    ) {
    }
}
