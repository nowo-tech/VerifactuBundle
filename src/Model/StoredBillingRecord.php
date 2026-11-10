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

    /**
     * Creates a stored record.
     */
    public function __construct(
        public readonly int $position,
        public readonly BillingRecord $record,
        public readonly string $submissionStatus = self::STATUS_PENDING,
        public readonly ?string $submissionReference = null,
    ) {
    }
}
