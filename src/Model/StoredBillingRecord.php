<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

/**
 * A billing record read back from the record store, with its position in the issuer chain and its submission state.
 *
 * `record` carries the hashed fields, the hash, the previous hash and the XML (lines and tax breakdown are not stored:
 * the XML holds them).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class StoredBillingRecord
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_FAILED   = 'failed';

    /** Claimed by a batch submission in flight (1.7). */
    public const STATUS_SENDING = 'sending';

    /** AEAT AceptadoConErrores: registered, with errors to correct (1.7). */
    public const STATUS_ACCEPTED_WITH_ERRORS = 'accepted_errors';

    /** AEAT Incorrecto: not registered; correct it with a new record (Subsanacion S, RechazoPrevio X) (1.7). */
    public const STATUS_REJECTED = 'rejected';

    /**
     * AEAT RegistroDuplicado for a record sent before, not confirmed to be this record (1.7): never reported as
     * accepted, never sent again automatically.
     */
    public const STATUS_UNCONFIRMED = 'unconfirmed';

    /**
     * Suspect (1.7, circuit breaker): the AEAT refused the record on its own with a fault that does not say whether the
     * record or the issuer is at fault. Set aside while other records are sent alone as probes; never waiting; listed by
     * `submit-pending --list`.
     */
    public const STATUS_HELD = 'held';

    /**
     * A suspect after a probe was answered (1.7): the AEAT accepts this issuer's requests, so the record is sent alone
     * once more; refused again, it is marked failed, otherwise it takes its answer.
     */
    public const STATUS_CONFIRMING = 'confirming';

    /**
     * Creates a stored record.
     *
     * @param int $submissionAttempts AEAT requests that carried this record (counted when the request is transmitted)
     * @param int $submissionFailures consecutive requests that carried this record and got an AEAT answer without a
     *                                per-record outcome (SoapFault, HTTP error, unreadable body): halves the next batch
     */
    public function __construct(
        public readonly int $position,
        public readonly BillingRecord $record,
        public readonly string $submissionStatus = self::STATUS_PENDING,
        public readonly ?string $submissionReference = null,
        public readonly int $submissionAttempts = 0,
        public readonly ?int $submissionErrorCode = null,
        public readonly ?string $submissionError = null,
        public readonly int $submissionFailures = 0,
    ) {
    }
}
