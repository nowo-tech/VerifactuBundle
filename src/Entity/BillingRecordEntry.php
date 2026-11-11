<?php

declare(strict_types=1);

namespace Nowo\VerifactuBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One billing record of the optional record log (record_store.storage: doctrine), in chain order per issuer.
 *
 * Bundle-owned and referenced by IDs only (issuer NIF + position, or issuer NIF + hash): no association to host
 * entities. The bundle writes it through DBAL (DoctrineBillingRecordStore), never through a flush. Mapped with
 * attributes (standard hosts) and with XML (`src/Resources/config/doctrine/BillingRecordEntry.orm.xml`, selected with
 * `nowo_verifactu.doctrine.mapping: xml`).
 *
 * @author Nowo.tech
 * @copyright 2026 Nowo.tech
 */
#[ORM\Entity]
#[ORM\Table(name: 'verifactu_billing_record')]
#[ORM\UniqueConstraint(name: 'uniq_verifactu_billing_record_hash', columns: ['issuer_nif', 'hash'])]
#[ORM\Index(name: 'idx_verifactu_billing_record_submission', columns: ['issuer_nif', 'submission_status', 'position'])]
class BillingRecordEntry
{
    #[ORM\Id]
    #[ORM\Column(name: 'issuer_nif', type: Types::STRING, length: 20)]
    private string $issuerNif;

    #[ORM\Id]
    #[ORM\Column(name: 'position', type: Types::INTEGER)]
    private int $position;

    #[ORM\Column(name: 'record_type', type: Types::STRING, length: 10)]
    private string $recordType;

    #[ORM\Column(name: 'invoice_series_number', type: Types::STRING, length: 60)]
    private string $invoiceSeriesNumber;

    #[ORM\Column(name: 'issue_date', type: Types::STRING, length: 10)]
    private string $issueDate;

    #[ORM\Column(name: 'invoice_type', type: Types::STRING, length: 2)]
    private string $invoiceType;

    #[ORM\Column(name: 'total_tax_amount', type: Types::STRING, length: 20)]
    private string $totalTaxAmount;

    #[ORM\Column(name: 'total_amount', type: Types::STRING, length: 20)]
    private string $totalAmount;

    #[ORM\Column(name: 'generated_at', type: Types::STRING, length: 32)]
    private string $generatedAt;

    #[ORM\Column(name: 'previous_hash', type: Types::STRING, length: 64)]
    private string $previousHash;

    #[ORM\Column(name: 'hash', type: Types::STRING, length: 64)]
    private string $hash;

    #[ORM\Column(name: 'issuer_name', type: Types::STRING, length: 120, nullable: true)]
    private ?string $issuerName;

    #[ORM\Column(name: 'xml', type: Types::TEXT)]
    private string $xml;

    #[ORM\Column(name: 'signed_xml', type: Types::TEXT, nullable: true)]
    private ?string $signedXml;

    #[ORM\Column(name: 'submission_status', type: Types::STRING, length: 16)]
    private string $submissionStatus;

    #[ORM\Column(name: 'submission_reference', type: Types::STRING, length: 120, nullable: true)]
    /** @phpstan-ignore property.unusedType (written by DoctrineBillingRecordStore::markSubmitted() through DBAL) */
    private ?string $submissionReference = null;

    /** AEAT environment the record was chained for (sandbox / production); null for rows written before 1.6. */
    #[ORM\Column(name: 'environment', type: Types::STRING, length: 10, nullable: true)]
    private ?string $environment;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    /** Number of AEAT submissions of this record (1.7, batch submission). */
    #[ORM\Column(name: 'submission_attempts', type: Types::INTEGER, options: ['default' => 0])]
    private int $submissionAttempts = 0;

    /** Token of the submission that claimed the record while it is in flight (1.7). */
    #[ORM\Column(name: 'submission_claim', type: Types::STRING, length: 32, nullable: true)]
    /** @phpstan-ignore property.unusedType, property.onlyWritten (written by DoctrineBillingRecordStore through DBAL) */
    private ?string $submissionClaim = null;

    /** When the in-flight claim was taken (1.7). */
    #[ORM\Column(name: 'submission_claimed_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    /** @phpstan-ignore property.unusedType, property.onlyWritten (written by DoctrineBillingRecordStore through DBAL) */
    private ?DateTimeImmutable $submissionClaimedAt = null;

    /** AEAT CodigoErrorRegistro of the last answer (1.7). */
    #[ORM\Column(name: 'submission_error_code', type: Types::INTEGER, nullable: true)]
    /** @phpstan-ignore property.unusedType (written by DoctrineBillingRecordStore through DBAL) */
    private ?int $submissionErrorCode = null;

    /** AEAT DescripcionErrorRegistro of the last answer, or the failure of the last attempt (1.7). */
    #[ORM\Column(name: 'submission_error', type: Types::TEXT, nullable: true)]
    /** @phpstan-ignore property.unusedType (written by DoctrineBillingRecordStore through DBAL) */
    private ?string $submissionError = null;

    /** When the AEAT answered for this record (1.7). */
    #[ORM\Column(name: 'submitted_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    /** @phpstan-ignore property.unusedType (written by DoctrineBillingRecordStore through DBAL) */
    private ?DateTimeImmutable $submittedAt = null;

    /**
     * Creates a log entry (used by tests and hosts that read the table through the ORM; the bundle writes it with DBAL).
     */
    public function __construct(
        string $issuerNif,
        int $position,
        string $recordType,
        string $invoiceSeriesNumber,
        string $issueDate,
        string $invoiceType,
        string $totalTaxAmount,
        string $totalAmount,
        string $generatedAt,
        string $previousHash,
        string $hash,
        string $xml,
        ?string $signedXml = null,
        ?string $issuerName = null,
        string $submissionStatus = 'pending',
        ?string $environment = null,
    ) {
        $this->issuerNif           = strtoupper(trim($issuerNif));
        $this->position            = $position;
        $this->recordType          = $recordType;
        $this->invoiceSeriesNumber = $invoiceSeriesNumber;
        $this->issueDate           = $issueDate;
        $this->invoiceType         = $invoiceType;
        $this->totalTaxAmount      = $totalTaxAmount;
        $this->totalAmount         = $totalAmount;
        $this->generatedAt         = $generatedAt;
        $this->previousHash        = $previousHash;
        $this->hash                = $hash;
        $this->xml                 = $xml;
        $this->signedXml           = $signedXml;
        $this->issuerName          = $issuerName;
        $this->submissionStatus    = $submissionStatus;
        $this->environment         = $environment;
        $this->createdAt           = new DateTimeImmutable();
    }

    /**
     * Returns the issuer NIF (upper case).
     */
    public function getIssuerNif(): string
    {
        return $this->issuerNif;
    }

    /**
     * Returns the 1-based position of the record in the issuer chain.
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Returns the record hash (Huella).
     */
    public function getHash(): string
    {
        return $this->hash;
    }

    /**
     * Returns the hash of the previous record ('' for the first one).
     */
    public function getPreviousHash(): string
    {
        return $this->previousHash;
    }

    /**
     * Returns the record XML.
     */
    public function getXml(): string
    {
        return $this->xml;
    }

    /**
     * Returns the submission status (pending, accepted, failed).
     */
    public function getSubmissionStatus(): string
    {
        return $this->submissionStatus;
    }

    /**
     * Returns the AEAT environment the record was chained for (null for rows written before 1.6).
     */
    public function getEnvironment(): ?string
    {
        return $this->environment;
    }

    /**
     * Returns when the entry was created.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the record type (Alta, Anulacion).
     */
    public function getRecordType(): string
    {
        return $this->recordType;
    }

    /**
     * Returns the invoice series and number.
     */
    public function getInvoiceSeriesNumber(): string
    {
        return $this->invoiceSeriesNumber;
    }

    /**
     * Returns the invoice issue date (dd-mm-yyyy).
     */
    public function getIssueDate(): string
    {
        return $this->issueDate;
    }

    /**
     * Returns the invoice type (F1, R1, …).
     */
    public function getInvoiceType(): string
    {
        return $this->invoiceType;
    }

    /**
     * Returns CuotaTotal as written in the XML and the hash.
     */
    public function getTotalTaxAmount(): string
    {
        return $this->totalTaxAmount;
    }

    /**
     * Returns ImporteTotal as written in the XML and the hash.
     */
    public function getTotalAmount(): string
    {
        return $this->totalAmount;
    }

    /**
     * Returns FechaHoraHusoGenRegistro.
     */
    public function getGeneratedAt(): string
    {
        return $this->generatedAt;
    }

    /**
     * Returns the issuer name written in the XML, when the record carried one.
     */
    public function getIssuerName(): ?string
    {
        return $this->issuerName;
    }

    /**
     * Returns the signed XML, when the record was signed.
     */
    public function getSignedXml(): ?string
    {
        return $this->signedXml;
    }

    /**
     * Returns the number of AEAT submissions of this record.
     */
    public function getSubmissionAttempts(): int
    {
        return $this->submissionAttempts;
    }

    /**
     * Returns the AEAT CodigoErrorRegistro of the last answer, if any.
     */
    public function getSubmissionErrorCode(): ?int
    {
        return $this->submissionErrorCode;
    }

    /**
     * Returns the AEAT error description of the last answer, or the failure of the last attempt.
     */
    public function getSubmissionError(): ?string
    {
        return $this->submissionError;
    }

    /**
     * Returns when the AEAT answered for this record.
     */
    public function getSubmittedAt(): ?DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /**
     * Returns the AEAT reference (CSV) of an accepted submission.
     */
    public function getSubmissionReference(): ?string
    {
        return $this->submissionReference;
    }
}
