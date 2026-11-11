<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Event;

/**
 * Veri*Factu bundle event name constants.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class VerifactuEvents
{
    public const BEFORE_BILLING_RECORD_GENERATION = 'nowo_verifactu.before_billing_record_generation';
    public const AFTER_BILLING_RECORD_GENERATED   = 'nowo_verifactu.after_billing_record_generated';
    public const BEFORE_AEAT_SUBMISSION           = 'nowo_verifactu.before_aeat_submission';
    public const AFTER_AEAT_SUBMISSION            = 'nowo_verifactu.after_aeat_submission';

    /** One per record of a batch submission, after its AEAT answer is committed in the record log (1.7). */
    public const AFTER_RECORD_SUBMISSION = 'nowo_verifactu.after_record_submission';

    /**
     * Batch submission (1.7): once per record marked `failed` after it kept failing on its own (RecordSubmissionFailedEvent),
     * after that state is committed.
     */
    public const RECORD_SUBMISSION_FAILED = 'nowo_verifactu.record_submission_failed';
}
