<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Model;

use DateTimeImmutable;

/**
 * One stored record as read through BillingRecordQueryInterface (1.10): the hashed fields, the hash, the submission
 * state and the issuer position, without the XML (read the full record with BillingRecordStoreInterface::findByIssuer()
 * or ChainVerifier when you need it).
 *
 * A read-only copy: changing nothing here changes the record store.
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
final class BillingRecordView
{
    /**
     * Creates a view.
     *
     * @param string $issuerNif issuer NIF (upper case)
     * @param int $position 1-based position in the issuer chain
     * @param string $issueDate FechaExpedicionFactura (dd-mm-yyyy)
     * @param string $generatedAt FechaHoraHusoGenRegistro
     * @param string $previousHash hash of the previous record ('' for the first one)
     * @param string $hash Huella of this record
     * @param string $submissionStatus StoredBillingRecord::STATUS_* value
     * @param ?AeatEnvironment $environment AEAT environment the record was chained for (null before 1.6)
     * @param ?DateTimeImmutable $recordedAt when the record store wrote the row (null for stores that do
     *                                       not keep it, e.g. the in-memory store)
     * @param ?DateTimeImmutable $submittedAt when the AEAT answered for this record (batch submission)
     */
    public function __construct(
        public readonly string $issuerNif,
        public readonly int $position,
        public readonly RecordType $recordType,
        public readonly string $invoiceSeriesNumber,
        public readonly string $issueDate,
        public readonly string $invoiceType,
        public readonly string $totalTaxAmount,
        public readonly string $totalAmount,
        public readonly string $generatedAt,
        public readonly string $previousHash,
        public readonly string $hash,
        public readonly ?string $issuerName,
        public readonly ?AeatEnvironment $environment,
        public readonly string $submissionStatus,
        public readonly ?string $submissionReference = null,
        public readonly int $submissionAttempts = 0,
        public readonly ?int $submissionErrorCode = null,
        public readonly ?string $submissionError = null,
        public readonly ?DateTimeImmutable $recordedAt = null,
        public readonly ?DateTimeImmutable $submittedAt = null,
    ) {
    }

    /**
     * Builds the view of a record read back from a record store (no write or answer time: the store record has none).
     */
    public static function fromStored(StoredBillingRecord $stored): self
    {
        $record = $stored->record;

        return new self(
            strtoupper(trim($record->issuerNif)),
            $stored->position,
            $record->recordType,
            $record->invoiceSeriesNumber,
            $record->issueDate,
            $record->invoiceType,
            $record->totalTaxAmount,
            $record->totalAmount,
            $record->generatedAt,
            (string) $record->previousHash,
            (string) $record->hash,
            $record->issuerName,
            $record->environment,
            $stored->submissionStatus,
            $stored->submissionReference,
            $stored->submissionAttempts,
            $stored->submissionErrorCode,
            $stored->submissionError,
        );
    }
}
