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
     * Creates a stored record.
     */
    public function __construct(
        public readonly int $position,
        public readonly BillingRecord $record,
        public readonly string $submissionStatus = self::STATUS_PENDING,
        public readonly ?string $submissionReference = null,
        public readonly int $submissionAttempts = 0,
        public readonly ?int $submissionErrorCode = null,
        public readonly ?string $submissionError = null,
    ) {
    }
}
